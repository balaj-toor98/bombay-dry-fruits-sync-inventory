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

$row = dbFetchOne('SELECT sku, name, stock, price, compare_at_price FROM products WHERE sku = ? LIMIT 1', 's', [$sku]);

if (!$row) {
    echo "SKU {$sku} not found in database. Run fetch_crm.php first.\n";
    exit(1);
}

echo "DB: SKU={$row['sku']} stock={$row['stock']} price={$row['price']} compare_at={$row['compare_at_price']} name={$row['name']}\n";

loadShopifyInventoryCache(true);
$variant = resolveShopifyVariant($sku);

if ($variant === null) {
    echo "ERROR: barcode {$sku} not found in Shopify (check variant SKU or barcode field).\n";
    exit(1);
}

echo "Shopify variant_id: {$variant['variant_id']} | inventory_item_id: {$variant['inventory_item_id']}\n";
echo "Shopify current price: {$variant['price']} | compare_at: {$variant['compare_at_price']} | matched via: "
    . ($variant['sku'] === $sku ? 'SKU' : ($variant['barcode'] === $sku ? 'barcode' : 'case-insensitive'))
    . "\n";
echo "Location ID: " . SHOPIFY_LOCATION_ID . "\n";

$result = syncShopifyProductByBarcode($sku, (int) $row['stock'], (float) $row['price'], (float) $row['compare_at_price'], true);
$ok = $result['inventory'] === 'ok';

echo "Inventory: {$result['inventory']}\n";
echo "Price: {$result['price']}\n";
echo $ok ? "SUCCESS: inventory updated.\n" : "FAILED: see logs table.\n";
exit($ok ? 0 : 1);
