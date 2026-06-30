<?php
/**
 * Shopify Admin API integration
 */

declare(strict_types=1);

/**
 * Build Shopify Admin API base URL
 */
function shopifyApiUrl(string $endpoint): string
{
    $shop = rtrim(SHOPIFY_SHOP, '/');
    if (!str_contains($shop, '.myshopify.com')) {
        $shop .= '.myshopify.com';
    }
    $endpoint = ltrim($endpoint, '/');
    return sprintf(
        'https://%s/admin/api/%s/%s',
        $shop,
        SHOPIFY_API_VERSION,
        $endpoint
    );
}

/**
 * Shopify API request (relative endpoint or full URL)
 */
function shopifyRequest(string $method, string $endpointOrUrl, ?array $payload = null, array $extra = []): array
{
    $url = str_starts_with($endpointOrUrl, 'http')
        ? $endpointOrUrl
        : shopifyApiUrl($endpointOrUrl);

    $options = [
        'headers' => [
            'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN,
            'Content-Type: application/json',
        ],
    ];

    if (!empty($extra['include_headers'])) {
        $options['include_headers'] = true;
    }

    if ($payload !== null) {
        $options['json'] = $payload;
    }

    return httpRequest($method, $url, $options);
}

/**
 * Normalize SKU for matching CRM ↔ Shopify
 */
function normalizeSku(string $sku): string
{
    return trim($sku);
}

/**
 * Cache array key — prefix prevents PHP casting "21026" to int array key
 */
function shopifyCacheKey(string $sku): string
{
    return 'sku:' . normalizeSku($sku);
}

/**
 * SKU/barcode → variant data cache (all pages)
 * @var array<string, array{variant_id: int, inventory_item_id: int, price: float, compare_at_price: float, sku: string, barcode: string}>
 */
$GLOBALS['_shopify_variant_cache'] = [];
$GLOBALS['_shopify_variant_cache_loaded'] = false;

/**
 * Parse Shopify Link header for next page URL
 */
function shopifyNextPageUrl(array $headers): ?string
{
    $link = $headers['link'] ?? '';
    if ($link === '') {
        return null;
    }
    if (preg_match('/<([^>]+)>;\s*rel="next"/i', $link, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Index one variant in the cache by SKU and/or barcode
 */
function shopifyCacheVariant(array $variantData): void
{
    $sku = $variantData['sku'];
    $barcode = $variantData['barcode'];

    if ($sku !== '') {
        $GLOBALS['_shopify_variant_cache'][shopifyCacheKey($sku)] = $variantData;
    }
    if ($barcode !== '' && $barcode !== $sku) {
        $GLOBALS['_shopify_variant_cache']['barcode:' . $barcode] = $variantData;
    }
}

/**
 * Load ALL Shopify products + variants (paginated — fixes missing SKUs after product #250)
 * Indexes each variant by SKU and barcode for CRM ProductBarcode matching.
 */
function loadShopifyInventoryCache(bool $forceReload = false): void
{
    if ($GLOBALS['_shopify_variant_cache_loaded'] && !$forceReload) {
        return;
    }

    $GLOBALS['_shopify_variant_cache'] = [];
    $url = shopifyApiUrl('products.json?limit=250&fields=id,variants');
    $pages = 0;
    $variantCount = 0;

    while ($url !== null && $pages < 200) {
        $response = shopifyRequest('GET', $url, null, ['include_headers' => true]);
        $pages++;

        if (!$response['success'] || !is_array($response['json']['products'] ?? null)) {
            logError('Shopify product page fetch failed: HTTP ' . $response['status'] . ' — ' . substr($response['body'], 0, 200));
            break;
        }

        foreach ($response['json']['products'] as $product) {
            foreach ($product['variants'] ?? [] as $variant) {
                $variantSku = normalizeSku((string) ($variant['sku'] ?? ''));
                $variantBarcode = normalizeSku((string) ($variant['barcode'] ?? ''));
                $variantId = (int) ($variant['id'] ?? 0);
                $invId = (int) ($variant['inventory_item_id'] ?? 0);

                if ($invId <= 0 || $variantId <= 0) {
                    continue;
                }

                // Simple products often have barcode but empty SKU — still indexable
                if ($variantSku === '' && $variantBarcode === '') {
                    continue;
                }

                $variantData = [
                    'variant_id' => $variantId,
                    'inventory_item_id' => $invId,
                    'price' => round((float) ($variant['price'] ?? 0), 2),
                    'compare_at_price' => round((float) ($variant['compare_at_price'] ?? 0), 2),
                    'sku' => $variantSku,
                    'barcode' => $variantBarcode,
                ];

                shopifyCacheVariant($variantData);
                $variantCount++;
            }
        }

        $url = shopifyNextPageUrl($response['headers']);
    }

    $GLOBALS['_shopify_variant_cache_loaded'] = true;
    logSync("Shopify variant cache loaded: {$variantCount} variants from {$pages} page(s)");
}

/**
 * Resolve Shopify variant by CRM barcode — matches variant SKU first, then variant barcode
 *
 * @return array{variant_id: int, inventory_item_id: int, price: float, compare_at_price: float, sku: string, barcode: string}|null
 */
function resolveShopifyVariant(string $crmBarcode): ?array
{
    $crmBarcode = normalizeSku($crmBarcode);
    if ($crmBarcode === '') {
        return null;
    }

    loadShopifyInventoryCache();

    $cache = $GLOBALS['_shopify_variant_cache'];

    $skuKey = shopifyCacheKey($crmBarcode);
    if (isset($cache[$skuKey])) {
        return $cache[$skuKey];
    }

    $barcodeKey = 'barcode:' . $crmBarcode;
    if (isset($cache[$barcodeKey])) {
        return $cache[$barcodeKey];
    }

    // Case-insensitive fallback (SKU then barcode)
    foreach ($cache as $key => $variantData) {
        if (str_starts_with((string) $key, 'sku:')
            && strcasecmp(substr((string) $key, 4), $crmBarcode) === 0
        ) {
            return $variantData;
        }
        if (str_starts_with((string) $key, 'barcode:')
            && strcasecmp(substr((string) $key, 8), $crmBarcode) === 0
        ) {
            return $variantData;
        }
    }

    return null;
}

/**
 * Resolve Shopify inventory_item_id by CRM barcode (variant SKU or barcode)
 */
function getShopifyInventoryItemIdBySku(string $sku): ?int
{
    $variant = resolveShopifyVariant($sku);

    return $variant !== null ? $variant['inventory_item_id'] : null;
}

/**
 * Ensure inventory item is connected to configured location
 */
function connectShopifyInventoryToLocation(int $inventoryItemId, int $locationId): bool
{
    $response = shopifyRequest('POST', 'inventory_levels/connect.json', [
        'location_id' => $locationId,
        'inventory_item_id' => $inventoryItemId,
    ]);

    if ($response['success']) {
        return true;
    }

    if (in_array($response['status'], [422, 200], true)) {
        return true;
    }

    logWarning("Shopify connect inventory {$inventoryItemId} @{$locationId}: HTTP {$response['status']}");
    return false;
}

/**
 * GET inventory levels for one item (all locations)
 * @return array<int, array{location_id: int, available: int}>
 */
function getShopifyInventoryLevels(int $inventoryItemId): array
{
    $endpoint = 'inventory_levels.json?inventory_item_ids=' . $inventoryItemId;
    $response = shopifyRequest('GET', $endpoint);

    if (!$response['success'] || !is_array($response['json']['inventory_levels'] ?? null)) {
        return [];
    }

    $levels = [];
    foreach ($response['json']['inventory_levels'] as $row) {
        $levels[] = [
            'location_id' => (int) ($row['location_id'] ?? 0),
            'available' => (int) ($row['available'] ?? 0),
        ];
    }

    return $levels;
}

/**
 * SET absolute available qty at one location (NOT add — replaces value)
 */
function setShopifyInventoryAtLocation(int $inventoryItemId, int $locationId, int $available): bool
{
    connectShopifyInventoryToLocation($inventoryItemId, $locationId);

    $available = max(0, $available);
    $response = shopifyRequest('POST', 'inventory_levels/set.json', [
        'location_id' => $locationId,
        'inventory_item_id' => $inventoryItemId,
        'available' => $available,
    ]);

    return $response['success'];
}

/**
 * Set inventory for one SKU from API/DB stock (absolute, not additive)
 *
 * If store has multiple locations, Shopify UI may SUM them (e.g. 50 + 20 = 70).
 * SHOPIFY_ZERO_OTHER_LOCATIONS sets non-primary locations to 0.
 */
/**
 * @return 'ok'|'not_found'|'error'
 */
function setShopifyInventoryBySku(string $sku, int $quantity, bool $verbose = false): bool
{
    $result = setShopifyInventoryBySkuDetailed($sku, $quantity, $verbose);
    return $result === 'ok';
}

/**
 * @return 'ok'|'not_found'|'error'
 */
function setShopifyInventoryBySkuDetailed(string $sku, int $quantity, bool $verbose = false): string
{
    $sku = normalizeSku($sku);
    $variant = resolveShopifyVariant($sku);
    $inventoryItemId = $variant['inventory_item_id'] ?? null;

    if ($inventoryItemId === null) {
        if ($verbose) {
            logWarning("Shopify: barcode not found in store catalog (checked SKU + barcode): {$sku}");
        }
        return 'not_found';
    }

    $targetQty = max(0, $quantity);
    $primaryLocation = (int) SHOPIFY_LOCATION_ID;
    $zeroOthers = defined('SHOPIFY_ZERO_OTHER_LOCATIONS') && SHOPIFY_ZERO_OTHER_LOCATIONS;

    if ($verbose) {
        $levelsBefore = getShopifyInventoryLevels($inventoryItemId);
        if (count($levelsBefore) > 0) {
            $parts = [];
            foreach ($levelsBefore as $lv) {
                $parts[] = "loc{$lv['location_id']}={$lv['available']}";
            }
            logSync("Shopify SKU {$sku} BEFORE: " . implode(', ', $parts));
        }
    }

    // Optional: zero stock at all other locations (prevents 50+20=70 total in admin)
    if ($zeroOthers) {
        $levels = getShopifyInventoryLevels($inventoryItemId);
        foreach ($levels as $lv) {
            if ($lv['location_id'] !== $primaryLocation && $lv['available'] !== 0) {
                if (!setShopifyInventoryAtLocation($inventoryItemId, $lv['location_id'], 0)) {
                    logWarning("Shopify: could not zero location {$lv['location_id']} for SKU {$sku}");
                }
                usleep(150000);
            }
        }
    }

    if (!setShopifyInventoryAtLocation($inventoryItemId, $primaryLocation, $targetQty)) {
        logError("Shopify inventory SET failed for SKU {$sku} → {$targetQty} at location {$primaryLocation}");
        return 'error';
    }

    if ($verbose) {
        $levelsAfter = getShopifyInventoryLevels($inventoryItemId);
        $parts = [];
        $sum = 0;
        foreach ($levelsAfter as $lv) {
            $parts[] = "loc{$lv['location_id']}={$lv['available']}";
            $sum += $lv['available'];
        }
        logSync("Shopify SKU {$sku} SET to {$targetQty} @ loc{$primaryLocation}. AFTER: " . implode(', ', $parts) . " (sum={$sum})");
    }

    return 'ok';
}

/**
 * Update Shopify variant price + compare_at_price (skips if both unchanged)
 *
 * @return 'ok'|'skipped'|'error'
 */
function updateShopifyVariantPricing(
    int $variantId,
    float $price,
    float $compareAtPrice,
    ?float $currentPrice = null,
    ?float $currentCompareAtPrice = null
): string {
    $price = round(max(0, $price), 2);
    $compareAtPrice = round(max(0, $compareAtPrice), 2);

    if ($price <= 0) {
        return 'skipped';
    }

    $priceSame = $currentPrice !== null && abs($currentPrice - $price) < 0.01;
    $compareSame = $currentCompareAtPrice !== null && abs($currentCompareAtPrice - $compareAtPrice) < 0.01;
    if ($priceSame && $compareSame) {
        return 'skipped';
    }

    $variantPayload = [
        'id' => $variantId,
        'price' => number_format($price, 2, '.', ''),
    ];

    if ($compareAtPrice > 0) {
        $variantPayload['compare_at_price'] = number_format($compareAtPrice, 2, '.', '');
    } else {
        $variantPayload['compare_at_price'] = null;
    }

    $response = shopifyRequest('PUT', 'variants/' . $variantId . '.json', [
        'variant' => $variantPayload,
    ]);

    if (!$response['success']) {
        logError("Shopify price update failed for variant {$variantId} → price {$price}, compare_at {$compareAtPrice}: HTTP {$response['status']}");
        return 'error';
    }

    return 'ok';
}

/**
 * Update inventory + price for one CRM barcode (matches Shopify SKU or barcode)
 *
 * @return array{inventory: 'ok'|'not_found'|'error', price: 'ok'|'skipped'|'not_found'|'error'}
 */
function syncShopifyProductByBarcode(
    string $crmBarcode,
    int $stock,
    float $price,
    float $compareAtPrice = 0,
    bool $verbose = false
): array {
    $crmBarcode = normalizeSku($crmBarcode);
    $variant = resolveShopifyVariant($crmBarcode);

    if ($variant === null) {
        if ($verbose) {
            logWarning("Shopify: barcode not found (checked SKU + barcode): {$crmBarcode}");
        }
        return ['inventory' => 'not_found', 'price' => 'not_found'];
    }

    $inventoryResult = setShopifyInventoryBySkuDetailed($crmBarcode, $stock, $verbose);
    $priceResult = updateShopifyVariantPricing(
        $variant['variant_id'],
        $price,
        $compareAtPrice,
        $variant['price'],
        $variant['compare_at_price']
    );

    if ($verbose && $priceResult === 'ok') {
        logSync(sprintf(
            'Shopify price %s: %.2f → %.2f, compare_at %.2f → %.2f',
            $crmBarcode,
            $variant['price'],
            $price,
            $variant['compare_at_price'],
            $compareAtPrice
        ));
    }

    return ['inventory' => $inventoryResult, 'price' => $priceResult];
}

/**
 * Sync all (or filtered) products to Shopify — inventory + prices
 *
 * Fetches all Shopify products/variants once, maps CRM barcodes to variant SKU or barcode,
 * then updates stock and price per matched row.
 *
 * @param array<int, array<string, mixed>>|null $products null = all from DB
 * @return array{success: int, failed: int, not_in_shopify: int, api_errors: int, price_updated: int, price_skipped: int, price_errors: int, total: int}
 */
function syncShopifyInventory(?array $products = null): array
{
    if ($products === null) {
        $products = getAllProductsForSync();
    }

    // Rebuild full variant map every sync (paginated)
    loadShopifyInventoryCache(true);
    $shopifyVariantCount = count($GLOBALS['_shopify_variant_cache']);
    logSync("Shopify variants in store: {$shopifyVariantCount} | CRM rows to sync: " . count($products));
    if (php_sapi_name() === 'cli') {
        echo "Shopify variants loaded: {$shopifyVariantCount}\n";
        echo "CRM rows in DB: " . count($products) . " (matched by SKU or barcode)\n";
    }

    $total = count($products);
    $success = 0;
    $notInShopify = 0;
    $apiFailed = 0;
    $priceUpdated = 0;
    $priceSkipped = 0;
    $priceErrors = 0;
    $processed = 0;

    logSync('Starting Shopify inventory + price sync for ' . $total . ' products (may take 30–90 min)');

    foreach ($products as $product) {
        $sku = normalizeSku((string) $product['sku']);
        $stock = (int) $product['stock'];
        $price = (float) ($product['price'] ?? 0);
        $compareAtPrice = (float) ($product['compare_at_price'] ?? 0);
        $processed++;

        $result = syncShopifyProductByBarcode($sku, $stock, $price, $compareAtPrice, false);

        if ($result['inventory'] === 'ok') {
            $success++;
        } elseif ($result['inventory'] === 'not_found') {
            $notInShopify++;
        } else {
            $apiFailed++;
        }

        if ($result['price'] === 'ok') {
            $priceUpdated++;
        } elseif ($result['price'] === 'skipped') {
            $priceSkipped++;
        } elseif ($result['price'] === 'error') {
            $priceErrors++;
        }

        if ($processed % 100 === 0 || $processed === $total) {
            logSync("Shopify progress: {$processed}/{$total} (stock_ok={$success}, not_in_shopify={$notInShopify}, stock_errors={$apiFailed}, price_updated={$priceUpdated})");
            if (php_sapi_name() === 'cli') {
                echo "  … {$processed}/{$total} stock_ok={$success} missing={$notInShopify} price_updated={$priceUpdated}\n";
            }
        }

        usleep(250000);
    }

    // MySQL may drop idle connections during long Shopify API runs (~10+ min)
    dbReconnect();
    updateSyncMeta('last_shopify_sync');
    $failed = $notInShopify + $apiFailed;
    logSync("Shopify sync done: {$success} stock updated, {$priceUpdated} prices updated, {$notInShopify} not in Shopify, {$apiFailed} stock errors, {$priceErrors} price errors");

    return [
        'success' => $success,
        'failed' => $failed,
        'not_in_shopify' => $notInShopify,
        'api_errors' => $apiFailed,
        'price_updated' => $priceUpdated,
        'price_skipped' => $priceSkipped,
        'price_errors' => $priceErrors,
        'total' => $total,
    ];
}

/**
 * Create order in Shopify from external platform (Foodpanda)
 */
function createShopifyOrder(array $orderData): array
{
    $lineItems = [];

    foreach ($orderData['line_items'] ?? [] as $item) {
        $sku = normalizeSku((string) ($item['sku'] ?? ''));
        $qty = max(1, (int) ($item['quantity'] ?? 1));
        $price = (string) ($item['price'] ?? '0.00');
        $title = (string) ($item['title'] ?? $sku);

        if ($sku === '') {
            continue;
        }

        $variantId = getShopifyVariantIdBySku($sku);

        $lineItem = [
            'quantity' => $qty,
            'price' => $price,
            'title' => $title,
            'sku' => $sku,
        ];

        if ($variantId !== null) {
            $lineItem['variant_id'] = $variantId;
        }

        $lineItems[] = $lineItem;
    }

    if (count($lineItems) === 0) {
        return ['success' => false, 'order_id' => null, 'error' => 'No valid line items'];
    }

    $customer = $orderData['customer'] ?? [];
    $payload = [
        'order' => [
            'line_items' => $lineItems,
            'financial_status' => $orderData['financial_status'] ?? 'paid',
            'fulfillment_status' => $orderData['fulfillment_status'] ?? null,
            'send_receipt' => false,
            'send_fulfillment_receipt' => false,
            'note' => $orderData['note'] ?? 'Imported from Foodpanda',
            'tags' => $orderData['tags'] ?? 'foodpanda,imported',
            'customer' => [
                'first_name' => $customer['first_name'] ?? 'Foodpanda',
                'last_name' => $customer['last_name'] ?? 'Customer',
                'phone' => $customer['phone'] ?? null,
                'email' => $customer['email'] ?? null,
            ],
        ],
    ];

    if (!empty($orderData['external_id'])) {
        $payload['order']['note'] .= ' | External ID: ' . $orderData['external_id'];
    }

    $response = shopifyRequest('POST', 'orders.json', $payload);

    if (!$response['success']) {
        $err = 'Shopify order create failed: HTTP ' . $response['status'] . ' — ' . $response['body'];
        logError($err);
        return ['success' => false, 'order_id' => null, 'error' => $err];
    }

    $orderId = (int) ($response['json']['order']['id'] ?? 0);
    logWebhook('Shopify order created: ID ' . $orderId);

    return ['success' => true, 'order_id' => $orderId > 0 ? $orderId : null, 'error' => null];
}

/**
 * Get variant ID by CRM barcode (variant SKU or barcode)
 */
function getShopifyVariantIdBySku(string $sku): ?int
{
    $variant = resolveShopifyVariant($sku);

    return $variant !== null ? $variant['variant_id'] : null;
}

/**
 * Parse Shopify order webhook line items → SKU qty map
 * @return array<int, array{sku: string, quantity: int}>
 */
function parseShopifyOrderLineItems(array $payload): array
{
    $items = [];
    $lineItems = $payload['line_items'] ?? [];

    foreach ($lineItems as $line) {
        $sku = normalizeSku((string) ($line['sku'] ?? ''));
        if ($sku === '') {
            continue;
        }
        $items[] = [
            'sku' => $sku,
            'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
        ];
    }

    return $items;
}
