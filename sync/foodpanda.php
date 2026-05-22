<?php
/**
 * Foodpanda inventory sync runner
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

/**
 * Run full Foodpanda catalog bulk sync from DB
 */
function runFoodpandaSync(?array $products = null): array
{
    return syncFoodpandaInventory($products);
}

if (php_sapi_name() === 'cli' || (isset($_GET['run']) && $_GET['run'] === '1')) {
    try {
        $result = runFoodpandaSync();
        $output = json_encode([
            'status' => 'ok',
            'success' => $result['success'],
            'failed' => $result['failed'],
            'jobs' => $result['jobs'],
        ]);
        if (php_sapi_name() === 'cli') {
            echo $output . PHP_EOL;
        } else {
            header('Content-Type: application/json');
            echo $output;
        }
    } catch (Throwable $e) {
        logError('Foodpanda sync error: ' . $e->getMessage());
        $msg = json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        if (php_sapi_name() === 'cli') {
            echo $msg . PHP_EOL;
            exit(1);
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo $msg;
    }
}
