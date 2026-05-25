<?php
/**
 * CRON: Daily CRM inventory fetch
 * Schedule: once per day (e.g. 0 2 * * *)
 * php /path/to/cron/fetch_crm.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

logCron('=== CRM fetch cron started ===');

try {
    $products = fetchCRMData();
    $saved = saveProductsToDB($products);

    // After CRM import, sync to both platforms
    $shopifyResult = syncShopifyInventory();
    $foodpandaResult = syncFoodpandaInventory();

    $summary = sprintf(
        'CRM cron complete: %d saved, Shopify %d updated (%d not in store, %d errors), Foodpanda %d sent',
        $saved,
        $shopifyResult['success'],
        $shopifyResult['not_in_shopify'] ?? 0,
        $shopifyResult['api_errors'] ?? 0,
        $foodpandaResult['success']
    );

    logCron($summary);

    if (php_sapi_name() === 'cli') {
        echo $summary . PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'saved' => $saved,
            'shopify' => $shopifyResult,
            'foodpanda' => $foodpandaResult,
        ]);
    }
} catch (Throwable $e) {
    logError('CRM cron failed: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

logCron('=== CRM fetch cron finished ===');
