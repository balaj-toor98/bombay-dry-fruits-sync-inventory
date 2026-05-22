<?php
/**
 * CRON: Sync all inventory from MySQL to Foodpanda Catalog API
 * php /path/to/cron/sync_foodpanda.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

logCron('=== Foodpanda sync cron started ===');

try {
    $result = syncFoodpandaInventory();
    $msg = sprintf(
        'Foodpanda sync: %d SKUs sent, %d failed, jobs: %s',
        $result['success'],
        $result['failed'],
        implode(',', $result['jobs'])
    );
    logCron($msg);

    if (php_sapi_name() === 'cli') {
        echo $msg . PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok'] + $result);
    }
} catch (Throwable $e) {
    logError('Foodpanda cron: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

logCron('=== Foodpanda sync cron finished ===');
