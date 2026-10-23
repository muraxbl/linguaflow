<?php
/**
 * LinguaFlow AI - try the translation engine from the command line, without PrestaShop.
 *
 * Settings come from environment variables so no key is ever written to a file:
 *
 *   LFAI_PROVIDER=openwebui LFAI_BASE_URL=https://ai.example.com LFAI_MODEL=gemma4:12b \
 *   LFAI_API_KEY=... php tools/try-translation.php --to=FR --glossary=glossary.json --field=Description input.html
 *
 * Options:
 *   --to=FR              target language code (EN, FR, IT, PT, DE...)
 *   --from=ES            source language code (default ES)
 *   --field=Description  Name | Description | Meta title | Meta description | Label
 *   --glossary=FILE      glossary.json ({"term": {"EN": "...", "FR": "..."}})
 *   --hint="..."         category hint
 *   --list-models        only list the provider's models
 *
 * The input is the file given as last argument, or standard input.
 *
 * @license AFL-3.0
 */
if (PHP_SAPI !== 'cli') {
    exit;
}

spl_autoload_register(function ($class) {
    if (strpos($class, 'LinguaFlowAI\\') === 0) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen('LinguaFlowAI\\'))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use LinguaFlowAI\Provider\ProviderConfig;
use LinguaFlowAI\Provider\ProviderFactory;
use LinguaFlowAI\Translation\GlossaryMatcher;
use LinguaFlowAI\Translation\LanguageInfo;
use LinguaFlowAI\Translation\MemoryInterface;
use LinguaFlowAI\Translation\PromptBuilder;
use LinguaFlowAI\Translation\ProtectedTerms;
use LinguaFlowAI\Translation\QualityCheck;
use LinguaFlowAI\Translation\SegmentTranslator;

/** In-memory translation memory for one run. */
final class RunMemory implements MemoryInterface
{
    private $rows = [];

    public function get(string $key): ?string
    {
        return $this->rows[$key] ?? null;
    }

    public function put(string $key, string $status, string $langCode, string $field, string $model, string $source, string $target): void
    {
        $this->rows[$key] = $target;
    }
}

$options = getopt('', ['to:', 'from:', 'field:', 'glossary:', 'hint:', 'list-models']);
$env = function ($name, $default = '') {
    $value = getenv($name);

    return $value === false ? $default : $value;
};

$config = new ProviderConfig(
    $env('LFAI_PROVIDER', 'ollama_local'),
    $env('LFAI_API_KEY'),
    $env('LFAI_BASE_URL'),
    $env('LFAI_MODEL'),
    $env('LFAI_EFFORT', 'medium'),
    (int) $env('LFAI_TIMEOUT', '300'),
    (int) $env('LFAI_NUM_CTX', '0'),
    $env('LFAI_THINK', '0') !== '1'
);
$provider = ProviderFactory::create($config);

if (isset($options['list-models'])) {
    echo implode("\n", $provider->listModels()), "\n";
    exit(0);
}

$args = array_values(array_filter(array_slice($argv, 1), function ($a) {
    return strpos($a, '--') !== 0;
}));
$input = $args ? file_get_contents(end($args)) : stream_get_contents(STDIN);
$to = strtoupper($options['to'] ?? 'EN');
$from = strtoupper($options['from'] ?? 'ES');
$field = $options['field'] ?? 'Description';
$terms = isset($options['glossary']) ? (json_decode((string) file_get_contents($options['glossary']), true) ?: []) : [];

$glossary = new GlossaryMatcher($terms);
$translator = new SegmentTranslator(
    $provider,
    $config->model,
    new PromptBuilder(LanguageInfo::DEFAULT_STYLES),
    $glossary,
    new ProtectedTerms(
        ['Masterled', 'Mean Well', 'Philips', 'Osram', 'Ledvance', 'Samsung', 'Schneider Electric', 'Legrand'],
        ['I[PK]\s?\d{2}', 'SMD\s?\d{4}', '(?:E27|E14|E40|GU10|GU5[.,]3|MR16|G9|G4|GX53|T5|T8)']
    ),
    new RunMemory()
);

$job = [
    'sourceName' => LanguageInfo::name($from),
    'sourceCode' => $from,
    'langName' => LanguageInfo::name($to),
    'langCode' => $to,
    'field' => $field,
    'categoryHint' => $options['hint'] ?? '',
    'nameContext' => null,
    'noCache' => false,
];

$start = microtime(true);
$notes = [];
$output = $translator->translateText(trim($input), $job, $notes);
$issues = array_merge($notes, QualityCheck::issues(trim($input), $output, $field, $from, $to, $glossary));

echo $output, "\n\n";
fprintf(STDERR, "-- %d model calls, %.1f s\n", $translator->stats['calls'], microtime(true) - $start);
fprintf(STDERR, $issues ? "-- would be held for review:\n   - %s\n" : "-- no issues: would be saved directly\n", implode("\n   - ", $issues));
