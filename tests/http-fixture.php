<?php
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
// Run with php -S 127.0.0.1:PORT tests/http-fixture.php; never expose this fixture publicly.
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/redirect') { header('Location: http://attacker.invalid/models', true, 302); exit; }
if ($path === '/large') { echo str_repeat('x', 2097153); exit; }
if ($path === '/invalid') { echo 'PRIVATE_CANARY_MUST_NOT_APPEAR_IN_ERRORS'; exit; }
if ($path === '/error') { http_response_code(401); echo json_encode(['error' => ['message' => 'Bad token: audit-fixture-key']]); exit; }
echo json_encode(['data' => [['id' => 'fixture-model']]]);
