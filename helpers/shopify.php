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
 * SKU → inventory_item_id cache (all pages)
 * @var array<string, int>
 */
$GLOBALS['_shopify_inventory_cache'] = [];
$GLOBALS['_shopify_inventory_cache_loaded'] = false;

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
 * Load ALL Shopify variant SKUs (paginated — fixes missing SKUs after product #250)
 */
function loadShopifyInventoryCache(bool $forceReload = false): void
{
    if ($GLOBALS['_shopify_inventory_cache_loaded'] && !$forceReload) {
        return;
    }

    $GLOBALS['_shopify_inventory_cache'] = [];
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
                $invId = (int) ($variant['inventory_item_id'] ?? 0);
                if ($variantSku !== '' && $invId > 0) {
                    $GLOBALS['_shopify_inventory_cache'][$variantSku] = $invId;
                    $variantCount++;
                }
            }
        }

        $url = shopifyNextPageUrl($response['headers']);
    }

    $GLOBALS['_shopify_inventory_cache_loaded'] = true;
    logSync("Shopify SKU cache loaded: {$variantCount} variants from {$pages} page(s)");
}

/**
 * Resolve Shopify inventory_item_id by variant SKU
 */
function getShopifyInventoryItemIdBySku(string $sku): ?int
{
    $sku = normalizeSku($sku);
    loadShopifyInventoryCache();

    if (isset($GLOBALS['_shopify_inventory_cache'][$sku])) {
        return (int) $GLOBALS['_shopify_inventory_cache'][$sku];
    }

    // Case-insensitive fallback
    foreach ($GLOBALS['_shopify_inventory_cache'] as $cachedSku => $invId) {
        if (strcasecmp($cachedSku, $sku) === 0) {
            return (int) $invId;
        }
    }

    return null;
}

/**
 * Ensure inventory item is connected to configured location
 */
function connectShopifyInventoryToLocation(int $inventoryItemId): bool
{
    $response = shopifyRequest('POST', 'inventory_levels/connect.json', [
        'location_id' => SHOPIFY_LOCATION_ID,
        'inventory_item_id' => $inventoryItemId,
    ]);

    if ($response['success']) {
        return true;
    }

    // Already connected or minor issue
    if (in_array($response['status'], [422, 200], true)) {
        return true;
    }

    logWarning("Shopify connect inventory {$inventoryItemId}: HTTP {$response['status']} — {$response['body']}");
    return false;
}

/**
 * Set inventory level for one SKU
 */
function setShopifyInventoryBySku(string $sku, int $quantity): bool
{
    $sku = normalizeSku($sku);
    $inventoryItemId = getShopifyInventoryItemIdBySku($sku);

    if ($inventoryItemId === null) {
        logWarning("Shopify: SKU not found in store catalog: {$sku}");
        return false;
    }

    connectShopifyInventoryToLocation($inventoryItemId);

    $available = max(0, $quantity);
    $response = shopifyRequest('POST', 'inventory_levels/set.json', [
        'location_id' => SHOPIFY_LOCATION_ID,
        'inventory_item_id' => $inventoryItemId,
        'available' => $available,
    ]);

    if (!$response['success']) {
        logError("Shopify inventory set failed for SKU {$sku} (qty {$available}): HTTP {$response['status']} — {$response['body']}");
        return false;
    }

    logSync("Shopify inventory set OK: SKU {$sku} → {$available}");
    return true;
}

/**
 * Sync all (or filtered) products to Shopify inventory
 *
 * @param array<int, array<string, mixed>>|null $products null = all from DB
 * @return array{success: int, failed: int}
 */
function syncShopifyInventory(?array $products = null): array
{
    if ($products === null) {
        $products = getAllProductsForSync();
    }

    // Rebuild full SKU map every sync (paginated)
    loadShopifyInventoryCache(true);

    $success = 0;
    $failed = 0;

    logSync('Starting Shopify inventory sync for ' . count($products) . ' products');

    foreach ($products as $product) {
        $sku = normalizeSku((string) $product['sku']);
        $stock = (int) $product['stock'];

        if (setShopifyInventoryBySku($sku, $stock)) {
            $success++;
        } else {
            $failed++;
        }

        usleep(250000);
    }

    updateSyncMeta('last_shopify_sync');
    logSync("Shopify sync done: {$success} ok, {$failed} failed");

    return ['success' => $success, 'failed' => $failed];
}

/**
 * Validate Shopify webhook HMAC signature
 */
function validateShopifyWebhook(string $rawBody, string $hmacHeader): bool
{
    if ($hmacHeader === '' || SHOPIFY_WEBHOOK_SECRET === '') {
        return false;
    }

    $calculated = base64_encode(
        hash_hmac('sha256', $rawBody, SHOPIFY_WEBHOOK_SECRET, true)
    );

    return hash_equals($calculated, $hmacHeader);
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

/** @var array<string, int> */
$GLOBALS['_shopify_variant_cache'] = [];

/**
 * Get variant ID by SKU (uses paginated cache)
 */
function getShopifyVariantIdBySku(string $sku): ?int
{
    $sku = normalizeSku($sku);

    if (isset($GLOBALS['_shopify_variant_cache'][$sku])) {
        return $GLOBALS['_shopify_variant_cache'][$sku];
    }

    $url = shopifyApiUrl('products.json?limit=250&fields=id,variants');
    $pages = 0;

    while ($url !== null && $pages < 200) {
        $response = shopifyRequest('GET', $url, null, ['include_headers' => true]);
        $pages++;

        if (!$response['success']) {
            return null;
        }

        foreach ($response['json']['products'] ?? [] as $product) {
            foreach ($product['variants'] ?? [] as $variant) {
                $variantSku = normalizeSku((string) ($variant['sku'] ?? ''));
                $variantId = (int) ($variant['id'] ?? 0);
                if ($variantSku !== '' && $variantId > 0) {
                    $GLOBALS['_shopify_variant_cache'][$variantSku] = $variantId;
                    if (strcasecmp($variantSku, $sku) === 0) {
                        return $variantId;
                    }
                }
            }
        }

        $url = shopifyNextPageUrl($response['headers']);
    }

    return null;
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
