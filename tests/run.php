<?php
require __DIR__ . '/bootstrap.php';

use LinguaFlowAI\Provider\HttpJsonClient;
use LinguaFlowAI\Provider\ProviderConfig;
use LinguaFlowAI\Queue\JobRepository;
use LinguaFlowAI\Security\ProductContent;
use LinguaFlowAI\Security\ShopScope;
use LinguaFlowAI\Translation\Html;
use LinguaFlowAI\Translation\ProductWriter;

$passed = 0;
function check($condition, $label) {
    global $passed;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    ++$passed;
}
function rejects(callable $action, $label) {
    $rejected = false;
    try { $action(); } catch (Throwable $e) { $rejected = true; }
    check($rejected, $label);
}
function child($scenario) {
    $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/controller.php') . ' ' . escapeshellarg($scenario), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($p);
    check($exit === 0, $scenario . ': no fatal error (' . $err . ')');
    return [json_decode($out, true), json_decode(trim($err), true)];
}

foreach (['file:///etc/passwd#', 'gopher://127.0.0.1/', 'ftp://example.com/', 'https://user:pass@example.com/', 'https://example.com/#fragment', "https://example.com/\r\n", 'https://example.com\\@attacker.invalid'] as $url) {
    rejects(function () use ($url) { (new HttpJsonClient(1))->request('GET', $url); }, 'Reject unsafe URL: ' . json_encode($url));
}
rejects(function () { new ProviderConfig('openai_compatible', '', 'https://example.com/?query=1', ''); }, 'Reject query in base URL');
rejects(function () { new ProviderConfig('openai', 'fixture', 'https://attacker.invalid', ''); }, 'Pin official cloud endpoint');
rejects(function () { new ProviderConfig('openai_compatible', 'fixture', 'http://example.com', ''); }, 'Do not send credentials over remote HTTP');
rejects(function () { new ProviderConfig('openai_compatible', "bad\r\nheader", 'https://example.com', ''); }, 'Reject header injection');
check((new ProviderConfig('ollama_local', '', '', ''))->baseUrl === 'http://localhost:11434', 'Local Ollama stays supported');
check((new ProviderConfig('openwebui', 'fixture', 'http://127.0.0.1:3000', ''))->baseUrl === 'http://127.0.0.1:3000', 'Loopback authenticated services stay supported');

foreach (['denied-view', 'denied-delete', 'denied-products', 'invalid-token', 'get-write', 'logged-out', 'glossary-readonly', 'glossary-mixed-submit', 'models-file', 'models-config-denied', 'models-shop-limited', 'models-wrong-url'] as $scenario) {
    list($out, $meta) = child($scenario);
    check($meta['status'] === 403 && $meta['queries'] === 0, $scenario . ': reject before DB changes / dispatch');
    check(!empty($out['errors']) || (!empty($out['error']) && $out['ok'] === false), $scenario . ': report denial');
}

check(ShopScope::targets('context') === [1], 'Context scope selects one shop');
check(ShopScope::targets('all') === [1, 2], 'Both-shop scope selects both authorized shops');
Context::getContext()->employee->shops = [1];
check(ShopScope::targets('all') === [1], 'Both-shop scope cannot expand employee permissions');
Context::getContext()->shop->id = 2;
rejects(function () { ShopScope::targets('context'); }, 'Reject unauthorized context shop');
Context::getContext()->shop->id = 1;
Shop::$scope = 2;
rejects(function () { ShopScope::targets('context'); }, 'Never guess a shop from group/all context');
check(ShopScope::targets('all') === [1], 'Explicit all scope works from group context');
Shop::$scope = 1;
Context::getContext()->employee->shops = [1, 2];

Html::validateMarkers('<t1>Translated text</t1>', [['marker' => '<t1>'], ['marker' => '</t1>']]);
foreach (['Lamp<img src=x onerror=alert(document.domain)>', '<t1>Lamp</t1><script>alert(document.domain)</script>'] as $raw) {
    rejects(function () use ($raw) { Html::validateMarkers($raw, []); }, 'Reject model-invented HTML');
}
check(ProductContent::sanitize('name', '<img src=x onerror=alert(document.domain)>Lamp') === 'Lamp', 'Plain fields cannot store tags');
if (class_exists('HTMLPurifier')) {
    foreach (['<p>lamp<img src=x onerror=alert(document.domain)></p>', '<svg onload=alert(document.domain)></svg>', '<a href="&#106;avascript:alert(document.domain)">click</a>', '<img src="data:text/html,test">', '<div style="background:url(javascript:alert(document.domain))">lamp</div>', '<script>alert(document.domain)</script><iframe src="https://example.com"></iframe>'] as $payload) {
        $safe = ProductContent::sanitize('description', $payload);
        check(!preg_match('/<\s*(script|iframe|svg|object|embed)\b|\son\w+\s*=|javascript:|data:text|\sstyle\s*=/i', $safe), 'Sanitize malicious description: ' . $payload);
    }
    $safe = ProductContent::sanitize('description', '<p><strong>Lamp</strong> <a href="https://example.com">details</a></p>');
    check(strpos($safe, '<strong>Lamp</strong>') !== false && strpos($safe, 'https://example.com') !== false, 'Preserve safe description formatting and links');
} else {
    rejects(function () { ProductContent::sanitize('description', '<p>text</p>'); }, 'Fail closed when the host has no HTMLPurifier');
    echo "HTMLPurifier integration: set LFAI_TEST_PURIFIER_AUTOLOAD to the host library.\n";
}

if (getenv('LFAI_TEST_HTTP_BASE')) {
    $base = getenv('LFAI_TEST_HTTP_BASE');
    $http = new HttpJsonClient(2);
    list($out, $meta) = child('models-saved-endpoint');
    check($out['ok'] && $out['models'] === ['fixture-model'], 'Saved endpoint still lists models with its saved key');
    check($http->request('GET', $base . '/models')['data'][0]['id'] === 'fixture-model', 'Read a real HTTP JSON response through the bounded callback');
    rejects(function () use ($http, $base) { $http->request('GET', $base . '/redirect'); }, 'Reject redirects');
    rejects(function () use ($http, $base) { $http->request('GET', $base . '/large'); }, 'Bound response size');
    foreach (['invalid', 'error'] as $path) {
        try { $http->request('GET', $base . '/' . $path, ['Authorization' => 'Bearer audit-fixture-key']); check(false, 'Expected HTTP fixture failure'); }
        catch (\LinguaFlowAI\Provider\ProviderException $e) { check(strpos($e->getMessage(), 'audit-fixture-key') === false && strpos($e->getMessage(), 'PRIVATE_CANARY') === false, 'Never disclose raw bodies or credentials in errors'); }
    }
}

if (getenv('LFAI_TEST_DB_SOCKET')) {
    $db = Db::getInstance();
    foreach (JobRepository::uninstallSql() as $sql) { $db->execute($sql); }
    foreach (JobRepository::installSql() as $sql) { $db->execute($sql); }
    foreach (['product_lang', 'product_shop', 'lang_shop', 'product'] as $table) { $db->execute('DROP TABLE IF EXISTS `test_' . $table . '`'); }
    $db->execute('CREATE TABLE test_product (id_product INT PRIMARY KEY, date_upd DATETIME NULL)');
    $db->execute('CREATE TABLE test_product_shop (id_product INT, id_shop INT, PRIMARY KEY (id_product,id_shop))');
    $db->execute('CREATE TABLE test_lang_shop (id_lang INT, id_shop INT, PRIMARY KEY (id_lang,id_shop))');
    $db->execute('CREATE TABLE test_product_lang (id_product INT, id_shop INT, id_lang INT, name TEXT, description TEXT, link_rewrite TEXT, PRIMARY KEY(id_product,id_shop,id_lang))');
    $db->execute('CREATE TABLE IF NOT EXISTS test_lang (id_lang INT PRIMARY KEY, name VARCHAR(32), iso_code VARCHAR(2))');
    $db->execute('CREATE TABLE IF NOT EXISTS test_category (id_category INT PRIMARY KEY, id_parent INT)');
    $db->execute('REPLACE INTO test_category VALUES (1,0)');
    $db->execute("REPLACE INTO test_lang VALUES (1,'Spanish','es'),(2,'English','en')");
    $db->execute('INSERT INTO test_product VALUES (42,NULL),(43,NULL)');
    $db->execute('INSERT INTO test_product_shop VALUES (42,1),(42,2),(43,2)');
    $db->execute('INSERT INTO test_lang_shop VALUES (1,1),(2,1),(1,2),(2,2)');
    $db->execute("INSERT INTO test_product_lang VALUES (42,1,1,'First source','Source one','source-one'),(42,2,1,'Second source','Source two','source-two'),(42,1,2,'First target','Before one','target-one'),(42,2,2,'Second target','Before two','target-two')");
    $repo = new JobRepository(null, [1]);
    $all = new JobRepository(null, [1, 2]);
    $job1 = $repo->createJob('manual', 'First shop', [42, 43], [2, 999], ['source_lang' => 1], 7, 1);
    $job2 = $all->createJob('manual', 'Second shop', [42], [2], ['source_lang' => 1], 7, 2);
    check($job1 > 0 && $job2 > $job1, 'Same product/language can be queued independently in both shops');
    check((int) $db->getValue('SELECT COUNT(*) FROM test_linguaflowai_item') === 2, 'Reject products and languages outside the target shop');
    rejects(function () use ($repo) { $repo->createJob('manual', 'Forbidden', [42], [2], [], 7, 2); }, 'Reject queue writes in unauthorized shops');
    check(count($repo->listJobs()) === 1 && count($repo->activeJobs()) === 1 && $repo->countDue() === 1, 'Scope job lists, active status and due counts');
    check((new JobRepository(null, []))->listJobs() === [], 'An empty authorization list grants no jobs');
    $item2 = $db->getRow('SELECT * FROM test_linguaflowai_item WHERE id_job=' . $job2);
    $all->markReview($item2, ['fields' => []]);
    check($repo->getJob($job2) === null && $repo->getItem((int) $item2['id_item']) === null, 'Reject cross-shop direct IDs');
    check($repo->countReview() === 0 && $repo->reviewItems(20) === [] && $repo->failedItems($job2) === [], 'Review and errors cannot disclose another shop');
    foreach (['cancelJob', 'retryFailed', 'deleteJob'] as $method) {
        rejects(function () use ($repo, $method, $job2) { $repo->$method($job2); }, 'Reject cross-shop ' . $method);
    }
    $db->execute("UPDATE test_linguaflowai_job SET status='completed',date_completed=NOW() WHERE id_job=" . $job2);
    check($repo->pullCompletedNotices(7) === [], 'Completion notices respect shop authorization');
    $db->execute("UPDATE test_linguaflowai_item SET status='processing',date_upd='2000-01-01'");
    $repo->releaseStale();
    check($db->getValue('SELECT status FROM test_linguaflowai_item WHERE id_job=' . $job2) === 'processing', 'Stale recovery cannot modify another shop');
    check((int) $repo->claimNext()['id_job'] === $job1, 'Worker claims only an authorized shop job');
    check(ProductWriter::loadLangRow(42, 1, 3) === null, 'Missing source never falls back to another shop');
    $memory = new class implements \LinguaFlowAI\Translation\MemoryInterface {
        public function get(string $key): ?string { return null; }
        public function put(string $key, string $status, string $langCode, string $field, string $model, string $source, string $target): void {}
    };
    $provider = new class implements \LinguaFlowAI\Provider\ProviderInterface {
        public function listModels(): array { return []; }
        public function complete(string $prompt, float $temperature): string { return strpos($prompt, 'First source') !== false ? 'First translation' : 'Second translation'; }
    };
    $translator = new \LinguaFlowAI\Translation\SegmentTranslator($provider, 'fixture', new \LinguaFlowAI\Translation\PromptBuilder([]), new \LinguaFlowAI\Translation\GlossaryMatcher([]), new \LinguaFlowAI\Translation\ProtectedTerms([], []), $memory);
    $service = new \LinguaFlowAI\Translation\ProductTranslationService($translator, $memory);
    $first = $service->translate(42, 1, 2, 1, ['fields' => ['name'], 'overwrite' => true]);
    $second = $service->translate(42, 1, 2, 2, ['fields' => ['name'], 'overwrite' => true]);
    check($first['fields']['name']['output'] === 'First translation' && $second['fields']['name']['output'] === 'Second translation', 'Translate each shop from its own source rather than copying one shop output');
    if (class_exists('HTMLPurifier')) {
        (new ProductWriter())->write(42, 1, 2, 1, ['description' => '<p>Translated<img src=x onerror=alert(document.domain)></p>'], ['slug' => false]);
        check(strpos($db->getValue('SELECT description FROM test_product_lang WHERE id_product=42 AND id_shop=1 AND id_lang=2'), 'onerror') === false, 'Actual SQL write is sanitized');
        check($db->getValue('SELECT description FROM test_product_lang WHERE id_product=42 AND id_shop=2 AND id_lang=2') === 'Before two', 'Context write leaves the second shop untouched');
        (new ProductWriter())->write(42, 1, 2, 2, ['description' => '<p>Second shop output</p>'], ['slug' => false]);
        check(Search::$shops === [1, 2] && (int) Context::getContext()->shop->id === 1 && Shop::getContextShopID() === 1, 'Index the target shop and restore the original context afterwards');
    }
    rejects(function () { (new ProductWriter())->write(43, 1, 2, 1, ['name' => 'Forbidden'], []); }, 'Reject a product outside the job shop');
    rejects(function () { (new ProductWriter())->write(42, 1, 1, 1, ['name' => 'Forbidden'], []); }, 'Never overwrite source language');
    rejects(function () { (new ProductWriter())->write(42, 1, 999, 1, ['name' => 'Forbidden'], []); }, 'Reject target languages outside the shop');
    try { \LinguaFlowAI\Security\ShopContext::run(2, function () { throw new RuntimeException('test'); }); } catch (RuntimeException $e) {}
    check((int) Context::getContext()->shop->id === 1 && Shop::getContextShopID() === 1, 'Restore shop context even when a translation throws');

    foreach (JobRepository::uninstallSql() as $sql) { $db->execute($sql); }
    foreach (JobRepository::installSql() as $sql) { $db->execute($sql); }
    list($out, $meta) = child('enqueue-both');
    check($out['ok'] && count($out['id_jobs']) === 2, 'Actual AJAX enqueue creates a job per authorized shop');
    check(array_map('intval', array_column($all->listJobs(), 'id_shop')) === [2, 1], 'Both shop jobs retain separate shop IDs');

    foreach (JobRepository::uninstallSql() as $sql) { $db->execute($sql); }
    foreach (JobRepository::installSql() as $sql) { $db->execute($sql); }
    $module = new LinguaFlowAI();
    $module->hookActionObjectProductAddAfter(['object' => new Product(42)]);
    check(count($all->listJobs()) === 1 && (int) $all->listJobs()[0]['id_shop'] === 1, 'Automatic context mode queues only the current shop');
    Configuration::$values['LFAI_SHOP_SCOPE'] = 'all';
    $module->hookActionObjectProductAddAfter(['object' => new Product(42)]);
    check(count($all->listJobs()) === 2, 'Automatic both-shop mode queues each associated shop separately');
    foreach (JobRepository::uninstallSql() as $sql) { $db->execute($sql); }
    foreach (JobRepository::installSql() as $sql) { $db->execute($sql); }
    Context::getContext()->employee->shops = [1];
    $module->hookActionObjectProductAddAfter(['object' => new Product(42)]);
    check(count($all->listJobs()) === 1, 'Automatic both-shop mode respects the employee shop boundary');
    Context::getContext()->employee->shops = [1, 2];
    Configuration::$values['LFAI_SHOP_SCOPE'] = 'context';

    $module = new LinguaFlowAI();
    $save = new ReflectionMethod($module, 'saveConfiguration'); $save->setAccessible(true);
    $before = Configuration::$values;
    Tools::$input = ['LFAI_PROVIDER' => 'openai_compatible', 'LFAI_BASE_URL' => 'file:///etc/passwd#'];
    check((bool) $save->invoke($module) && Configuration::$values === $before, 'Invalid configuration cannot partially change saved secrets');
    Tools::$input = ['LFAI_PROVIDER' => 'openai_compatible', 'LFAI_BASE_URL' => 'https://new.example/v1', 'LFAI_SOURCE_LANG' => 1, 'LFAI_TARGET_LANGS' => [2], 'LFAI_FIELDS' => ['name'], 'LFAI_SHOP_SCOPE' => 'context'];
    check($save->invoke($module) === [] && Configuration::$values['LFAI_API_KEY'] === '', 'Changing the endpoint cannot carry over the previous saved key');
    Configuration::$values['LFAI_PROVIDER'] = 'openai';
    Configuration::$values['LFAI_BASE_URL'] = '';
    Configuration::$values['LFAI_API_KEY'] = 'audit-fixture-key';
    Tools::$input['LFAI_PROVIDER'] = 'openai';
    Tools::$input['LFAI_BASE_URL'] = '';
    Tools::$input['LFAI_API_KEY_CLEAR'] = 1;
    check($save->invoke($module) === [] && Configuration::$values['LFAI_API_KEY'] === '', 'Explicitly removing a required cloud key really removes it');
}
echo "PASS: " . $passed . " security and multishop checks.\n";
