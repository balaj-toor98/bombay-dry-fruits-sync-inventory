<?php
/**
 * Compare CRM/DB products against Shopify and Foodpanda catalogs
 */

declare(strict_types=1);

/** @var array<string, string> */
$GLOBALS['_foodpanda_catalog_sku_cache'] = [];
$GLOBALS['_foodpanda_catalog_sku_cache_loaded'] = false;

/**
 * Load all Foodpanda catalog SKUs (paginated GET /catalog)
 */
function loadFoodpandaCatalogSkuCache(bool $forceReload = false): void
{
    if ($GLOBALS['_foodpanda_catalog_sku_cache_loaded'] && !$forceReload) {
        return;
    }

    $GLOBALS['_foodpanda_catalog_sku_cache'] = [];
    $page = 1;
    $pageSize = 100;

    while ($page <= 200) {
        $url = foodpandaCatalogUrl() . '?' . http_build_query([
            'locale' => FOODPANDA_LOCALE,
            'page_size' => $pageSize,
            'page' => $page,
        ]);

        $response = foodpandaRequest('GET', $url);

        if (!$response['success']) {
            logWarning('Foodpanda catalog page fetch failed: HTTP ' . $response['status'] . ' (page ' . $page . ')');
            break;
        }

        $products = $response['json']['products'] ?? $response['json']['data'] ?? [];
        if (!is_array($products) || count($products) === 0) {
            break;
        }

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $sku = normalizeSku((string) ($product['sku'] ?? ''));
            if ($sku !== '') {
                $GLOBALS['_foodpanda_catalog_sku_cache'][strtolower($sku)] = $sku;
            }
        }

        if (count($products) < $pageSize) {
            break;
        }

        $page++;
    }

    $GLOBALS['_foodpanda_catalog_sku_cache_loaded'] = true;
    logSync('Foodpanda catalog SKU cache loaded: ' . count($GLOBALS['_foodpanda_catalog_sku_cache']) . ' SKU(s)');
}

/**
 * Check if CRM barcode exists in Foodpanda catalog
 */
function isSkuInFoodpandaCatalog(string $sku): bool
{
    $sku = normalizeSku($sku);
    if ($sku === '') {
        return false;
    }

    loadFoodpandaCatalogSkuCache();
    $cache = $GLOBALS['_foodpanda_catalog_sku_cache'];

    if (isset($cache[strtolower($sku)])) {
        return true;
    }

    foreach ($cache as $cachedSku) {
        if (strcasecmp($cachedSku, $sku) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * CRM/DB products with no matching Shopify variant (SKU or barcode)
 *
 * @return array<int, array<string, mixed>>
 */
function getProductsMissingFromShopify(?array $products = null): array
{
    if ($products === null) {
        $products = getAllProductsForSync();
    }

    loadShopifyInventoryCache(true);

    $missing = [];
    foreach ($products as $product) {
        $sku = normalizeSku((string) ($product['sku'] ?? ''));
        if ($sku === '' || resolveShopifyVariant($sku) !== null) {
            continue;
        }

        $missing[] = $product;
    }

    return $missing;
}

/**
 * CRM/DB products whose SKU is not in Foodpanda catalog
 *
 * @return array<int, array<string, mixed>>
 */
function getProductsMissingFromFoodpanda(?array $products = null): array
{
    if ($products === null) {
        $products = getAllProductsForSync();
    }

    loadFoodpandaCatalogSkuCache(true);

    $missing = [];
    foreach ($products as $product) {
        $sku = normalizeSku((string) ($product['sku'] ?? ''));
        if ($sku === '' || isSkuInFoodpandaCatalog($sku)) {
            continue;
        }

        $missing[] = $product;
    }

    return $missing;
}

/**
 * @return array{shopify: array<int, array<string, mixed>>, foodpanda: array<int, array<string, mixed>>, total_crm: int}
 */
function getProductsNotUpdatedReport(): array
{
    $products = getAllProductsForSync();

    return [
        'total_crm' => count($products),
        'shopify' => getProductsMissingFromShopify($products),
        'foodpanda' => getProductsMissingFromFoodpanda($products),
    ];
}
