<?php
/**
 * Compare CRM SKUs in DB vs Shopify catalog (SSH diagnostic)
 * php cron/compare_skus.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

echo "Loading Shopify products + variants...\n";
loadShopifyInventoryCache(true);
$shopifySkus = [];
$shopifyBarcodes = [];
foreach (array_keys($GLOBALS['_shopify_variant_cache']) as $key) {
    if (str_starts_with((string) $key, 'sku:')) {
        $shopifySkus[] = substr((string) $key, 4);
    } elseif (str_starts_with((string) $key, 'barcode:')) {
        $shopifyBarcodes[] = substr((string) $key, 8);
    }
}

$crmRows = dbFetchAll('SELECT sku, name FROM products ORDER BY sku ASC');
$matched = 0;
$missing = 0;
$sampleMissing = [];

foreach ($crmRows as $row) {
    $sku = normalizeSku((string) $row['sku']);
    if (resolveShopifyVariant($sku) !== null) {
        $matched++;
    } else {
        $missing++;
        if (count($sampleMissing) < 20) {
            $sampleMissing[] = $sku . ' — ' . $row['name'];
        }
    }
}

echo "\n=== Barcode comparison ===\n";
echo 'CRM products in DB:      ' . count($crmRows) . "\n";
echo 'Shopify variant SKUs:    ' . count($shopifySkus) . "\n";
echo 'Shopify variant barcodes: ' . count($shopifyBarcodes) . "\n";
echo "Matched (will sync):     {$matched}\n";
echo "Missing in Shopify:      {$missing}\n";

if (count($sampleMissing) > 0) {
    echo "\nSample CRM barcodes NOT in Shopify (first 20):\n";
    foreach ($sampleMissing as $line) {
        echo "  - {$line}\n";
    }
}

echo "\nFix: set CRM ProductBarcode on Shopify variant SKU or barcode field.\n";
