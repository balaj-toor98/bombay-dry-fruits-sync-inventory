<?php
/**
 * Debug: sync one SKU to Shopify
 * CLI: php cron/sync_one_sku.php 21026
 * Web: /cron/sync_one_sku.php?sku=21026&key=YOUR_DASHBOARD_PASS
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

$sku = normalizeSku((string) ($argv[1] ?? $_GET['sku'] ?? ''));

if (php_sapi_name() !== 'cli') {
    $key = (string) ($_GET['key'] ?? '');
    if ($key === '' || !hash_equals(DASHBOARD_PASS, $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

if ($sku === '') {
    echo "Usage: php sync_one_sku.php 21026\n";
    exit(1);
}

$row = dbFetchOne('SELECT sku, name, stock, price FROM products WHERE sku = ? LIMIT 1', 's', [$sku]);

if (!$row) {
    echo "SKU {$sku} not found in database. Run fetch_crm.php first.\n";
    exit(1);
}

echo "DB: SKU={$row['sku']} stock={$row['stock']} name={$row['name']}\n";

loadShopifyInventoryCache(true);
$invId = getShopifyInventoryItemIdBySku($sku);

if ($invId === null) {
    echo "ERROR: SKU {$sku} not found in Shopify (check SKU field on variant).\n";
    exit(1);
}

echo "Shopify inventory_item_id: {$invId}\n";
echo "Location ID: " . SHOPIFY_LOCATION_ID . "\n";

    $ok = setShopifyInventoryBySku($sku, (int) $row['stock'], true);
echo $ok ? "SUCCESS: inventory updated.\n" : "FAILED: see logs table.\n";
exit($ok ? 0 : 1);
