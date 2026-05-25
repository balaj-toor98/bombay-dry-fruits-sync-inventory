<?php
/**
 * Compare CRM SKUs in DB vs Shopify catalog (SSH diagnostic)
 * php cron/compare_skus.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

echo "Loading Shopify SKU list...\n";
loadShopifyInventoryCache(true);
$shopifySkus = [];
foreach (array_keys($GLOBALS['_shopify_inventory_cache']) as $key) {
    if (str_starts_with((string) $key, 'sku:')) {
        $shopifySkus[] = substr((string) $key, 4);
    }
}
$shopifySet = array_flip($shopifySkus);

$crmRows = dbFetchAll('SELECT sku, name FROM products ORDER BY sku ASC');
$matched = 0;
$missing = 0;
$sampleMissing = [];

foreach ($crmRows as $row) {
    $sku = normalizeSku((string) $row['sku']);
    if (isset($shopifySet[$sku])) {
        $matched++;
    } else {
        $missing++;
        if (count($sampleMissing) < 20) {
            $sampleMissing[] = $sku . ' — ' . $row['name'];
        }
    }
}

echo "\n=== SKU comparison ===\n";
echo 'CRM products in DB:  ' . count($crmRows) . "\n";
echo 'Shopify variant SKUs: ' . count($shopifySkus) . "\n";
echo "Matched (will sync):  {$matched}\n";
echo "Missing in Shopify:   {$missing}\n";

if (count($sampleMissing) > 0) {
    echo "\nSample CRM SKUs NOT in Shopify (first 20):\n";
    foreach ($sampleMissing as $line) {
        echo "  - {$line}\n";
    }
}

echo "\nFix: set the same SKU on Shopify variant as CRM ProductBarcode.\n";
