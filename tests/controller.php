<?php
require __DIR__ . '/bootstrap.php';
$scenario = $argv[1];
$_SERVER['REQUEST_METHOD'] = 'POST';
$c = new AdminLinguaFlowAIController();
Tools::$input = ['id_job' => 123, 'submitLfaiDelete' => 1];
if ($scenario === 'denied-view') { $c->permissions['view'] = false; }
if ($scenario === 'denied-delete') { $c->permissions['delete'] = false; }
if ($scenario === 'denied-products') { Access::$denied = ['ROLE_MOD_TAB_ADMINPRODUCTS_UPDATE']; }
if ($scenario === 'invalid-token') { $c->validToken = false; }
if ($scenario === 'get-write') { $_SERVER['REQUEST_METHOD'] = 'GET'; }
if ($scenario === 'logged-out') { Context::getContext()->employee->logged = false; }
if (in_array($scenario, ['glossary-readonly', 'glossary-mixed-submit'], true)) {
    $c = new AdminLinguaFlowAIGlossaryController();
    $c->permissions['edit'] = false;
    Tools::$input = ['submitLfaiTerm' => 1, 'term' => 'payload'];
    if ($scenario === 'glossary-mixed-submit') { Tools::$input['submitLfaiDeleteTerm'] = 1; }
}
if (strpos($scenario, 'models-') === 0) {
    $c->ajax = true;
    Tools::$input = ['action' => 'ListModels', 'provider' => 'openai_compatible', 'base_url' => 'https://attacker.invalid'];
    if ($scenario === 'models-file') { Tools::$input['base_url'] = 'file:///etc/passwd#'; }
    if ($scenario === 'models-config-denied') { Access::$denied = ['ROLE_MOD_MODULE_LINGUAFLOWAI_UPDATE']; }
    if ($scenario === 'models-shop-limited') { Context::getContext()->employee->shops = [1]; }
    if ($scenario === 'models-saved-endpoint') {
        Configuration::$values['LFAI_BASE_URL'] = getenv('LFAI_TEST_HTTP_BASE');
        Tools::$input['base_url'] = getenv('LFAI_TEST_HTTP_BASE');
    }
}
if ($scenario === 'enqueue-both') {
    $c->ajax = true;
    Tools::$input = ['action' => 'EnqueueProducts', 'products' => [42], 'shop_scope' => 'all'];
}
register_shutdown_function(function () { fwrite(STDERR, json_encode(['queries' => Db::$queries, 'status' => http_response_code()]) . "\n"); });
$c->postProcess();
echo json_encode(['errors' => $c->errors]);
