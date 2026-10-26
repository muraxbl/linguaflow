<?php
/**
 * LinguaFlow AI
 *
 * @license AFL-3.0
 */

use LinguaFlowAI\Provider\ProviderException;
use LinguaFlowAI\Queue\Worker;
use LinguaFlowAI\Settings;

/**
 * Cron entry point: processes the queue without a back office tab open.
 * Example (every 5 minutes): curl -s "https://shop.example/module/linguaflowai/cron?token=XXXX"
 */
class LinguaFlowAICronModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function initContent()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $expected = (string) Settings::get(Settings::CRON_TOKEN);
        if ($expected === '' || !hash_equals($expected, (string) Tools::getValue('token'))) {
            http_response_code(403);
            exit(json_encode(['ok' => false, 'error' => 'Invalid token']));
        }

        ignore_user_abort(true);
        try {
            $stats = (new Worker())->run(max(1, min(200, (int) Tools::getValue('limit', 50))), max(10, min(840, (int) Tools::getValue('seconds', 240))));
            exit(json_encode(['ok' => true, 'stats' => $stats]));
        } catch (ProviderException $e) {
            http_response_code(500);
            exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
        }
    }
}
