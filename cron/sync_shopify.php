<?php
/**
 * CRON: Sync all inventory from MySQL to Shopify
 * Schedule: after CRM fetch or hourly
 * php /path/to/cron/sync_shopify.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

logCron('=== Shopify sync cron started ===');

try {
    $result = syncShopifyInventory();
    $msg = "Shopify sync: {$result['success']} ok, {$result['failed']} failed";
    logCron($msg);

    if (php_sapi_name() === 'cli') {
        echo $msg . PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok'] + $result);
    }
} catch (Throwable $e) {
    logError('Shopify cron: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

logCron('=== Shopify sync cron finished ===');
