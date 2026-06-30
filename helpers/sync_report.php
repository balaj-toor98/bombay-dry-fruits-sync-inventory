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
 * CRM/DB products with a matching Shopify variant (can sync inventory + price)
 *
 * @return array<int, array<string, mixed>>
 */
function getProductsUpdatedOnShopify(?array $products = null): array
{
    if ($products === null) {
        $products = getAllProductsForSync();
    }

    loadShopifyInventoryCache(true);

    $matched = [];
    foreach ($products as $product) {
        $sku = normalizeSku((string) ($product['sku'] ?? ''));
        if ($sku === '' || resolveShopifyVariant($sku) === null) {
            continue;
        }

        $matched[] = $product;
    }

    return $matched;
}

/**
 * CRM/DB products whose SKU exists in Foodpanda catalog (can sync inventory + price)
 *
 * @return array<int, array<string, mixed>>
 */
function getProductsUpdatedOnFoodpanda(?array $products = null): array
{
    if ($products === null) {
        $products = getAllProductsForSync();
    }

    loadFoodpandaCatalogSkuCache(true);

    $matched = [];
    foreach ($products as $product) {
        $sku = normalizeSku((string) ($product['sku'] ?? ''));
        if ($sku === '' || !isSkuInFoodpandaCatalog($sku)) {
            continue;
        }

        $matched[] = $product;
    }

    return $matched;
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

/**
 * @return array{shopify: array<int, array<string, mixed>>, foodpanda: array<int, array<string, mixed>>, total_crm: int}
 */
function getProductsUpdatedReport(): array
{
    $products = getAllProductsForSync();
    loadShopifyInventoryCache(true);
    loadFoodpandaCatalogSkuCache(true);

    $shopify = [];
    $foodpanda = [];

    foreach ($products as $product) {
        $sku = normalizeSku((string) ($product['sku'] ?? ''));
        if ($sku === '') {
            continue;
        }

        if (resolveShopifyVariant($sku) !== null) {
            $shopify[] = $product;
        }

        if (isSkuInFoodpandaCatalog($sku)) {
            $foodpanda[] = $product;
        }
    }

    return [
        'total_crm' => count($products),
        'shopify' => $shopify,
        'foodpanda' => $foodpanda,
    ];
}

/**
 * Slice a product list for dashboard pagination
 *
 * @param array<int, array<string, mixed>> $products
 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
 */
function paginateProductList(array $products, int $page = 1, int $perPage = 50): array
{
    $page = max(1, $page);
    $perPage = max(10, min(200, $perPage));
    $total = count($products);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    return [
        'items' => array_slice($products, $offset, $perPage),
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
    ];
}
