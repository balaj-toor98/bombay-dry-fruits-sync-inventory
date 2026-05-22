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
 * Shopify API request with access token
 */
function shopifyRequest(string $method, string $endpoint, ?array $payload = null): array
{
    $url = shopifyApiUrl($endpoint);
    $options = [
        'headers' => [
            'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN,
            'Content-Type: application/json',
        ],
    ];

    if ($payload !== null) {
        $options['json'] = $payload;
    }

    return httpRequest($method, $url, $options);
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
 * Cache: SKU or barcode → inventory_item_id (per request lifecycle)
 * @var array<string, int>
 */
$GLOBALS['_shopify_inventory_cache'] = [];

/**
 * Load Shopify variants indexed by SKU and barcode (CRM ProductBarcode e.g. "1NO")
 */
function loadShopifyVariantInventoryCache(): void
{
    if (count($GLOBALS['_shopify_inventory_cache']) > 0) {
        return;
    }

    $response = shopifyRequest('GET', 'products.json?limit=250&fields=id,variants');

    if (!$response['success'] || !is_array($response['json']['products'] ?? null)) {
        logWarning('Shopify product list fetch failed for SKU/barcode lookup');
        return;
    }

    foreach ($response['json']['products'] as $product) {
        foreach ($product['variants'] ?? [] as $variant) {
            $invId = (int) ($variant['inventory_item_id'] ?? 0);
            if ($invId <= 0) {
                continue;
            }

            $variantSku = trim((string) ($variant['sku'] ?? ''));
            $variantBarcode = trim((string) ($variant['barcode'] ?? ''));

            if ($variantSku !== '') {
                $GLOBALS['_shopify_inventory_cache'][$variantSku] = $invId;
            }
            if ($variantBarcode !== '') {
                $GLOBALS['_shopify_inventory_cache'][$variantBarcode] = $invId;
            }
        }
    }
}

/**
 * Resolve Shopify inventory_item_id by CRM barcode / SKU (e.g. "1NO")
 */
function getShopifyInventoryItemIdBySku(string $sku): ?int
{
    $sku = trim($sku);
    if ($sku === '') {
        return null;
    }

    loadShopifyVariantInventoryCache();

    if (isset($GLOBALS['_shopify_inventory_cache'][$sku])) {
        return (int) $GLOBALS['_shopify_inventory_cache'][$sku];
    }

    return null;
}

/**
 * Set inventory level for one SKU
 */
function setShopifyInventoryBySku(string $sku, int $quantity): bool
{
    $inventoryItemId = getShopifyInventoryItemIdBySku($sku);
    if ($inventoryItemId === null) {
        logWarning("Shopify: no inventory_item_id for SKU {$sku}");
        return false;
    }

    $response = shopifyRequest('POST', 'inventory_levels/set.json', [
        'location_id' => SHOPIFY_LOCATION_ID,
        'inventory_item_id' => $inventoryItemId,
        'available' => max(0, $quantity),
    ]);

    if (!$response['success']) {
        logError("Shopify inventory set failed for {$sku}: HTTP {$response['status']} — {$response['body']}");
        return false;
    }

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

    $success = 0;
    $failed = 0;

    logSync('Starting Shopify inventory sync for ' . count($products) . ' products');

    foreach ($products as $product) {
        $sku = (string) $product['sku'];
        $stock = (int) $product['stock'];

        if (setShopifyInventoryBySku($sku, $stock)) {
            $success++;
        } else {
            $failed++;
        }

        // Rate limit courtesy (2 calls/sec max on basic plans)
        usleep(250000);
    }

    updateSyncMeta('last_shopify_sync');
    logSync("Shopify sync done: {$success} ok, {$failed} failed");

    return ['success' => $success, 'failed' => $failed];
}

/**
 * Create order in Shopify from external platform (Foodpanda)
 *
 * @param array<string, mixed> $orderData
 *   - line_items: [{sku, quantity, price?, title?}]
 *   - customer: {first_name, last_name, phone?, email?}
 *   - note, tags, external_id
 * @return array{success: bool, order_id: ?int, error: ?string}
 */
function createShopifyOrder(array $orderData): array
{
    $lineItems = [];

    foreach ($orderData['line_items'] ?? [] as $item) {
        $sku = trim((string) ($item['sku'] ?? ''));
        $qty = max(1, (int) ($item['quantity'] ?? 1));
        $price = (string) ($item['price'] ?? '0.00');
        $title = (string) ($item['title'] ?? $sku);

        if ($sku === '') {
            continue;
        }

        // Resolve variant_id by SKU for proper line item linking
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
 * Cache: SKU or barcode → variant_id
 * @var array<string, int>
 */
$GLOBALS['_shopify_variant_id_cache'] = [];

/**
 * Get variant ID by CRM barcode / SKU (e.g. "1NO")
 */
function getShopifyVariantIdBySku(string $sku): ?int
{
    $sku = trim($sku);
    if ($sku === '') {
        return null;
    }

    if (count($GLOBALS['_shopify_variant_id_cache']) === 0) {
        $response = shopifyRequest('GET', 'products.json?limit=250&fields=id,variants');
        if ($response['success']) {
            foreach ($response['json']['products'] ?? [] as $product) {
                foreach ($product['variants'] ?? [] as $variant) {
                    $variantId = (int) ($variant['id'] ?? 0);
                    if ($variantId <= 0) {
                        continue;
                    }
                    $variantSku = trim((string) ($variant['sku'] ?? ''));
                    $variantBarcode = trim((string) ($variant['barcode'] ?? ''));
                    if ($variantSku !== '') {
                        $GLOBALS['_shopify_variant_id_cache'][$variantSku] = $variantId;
                    }
                    if ($variantBarcode !== '') {
                        $GLOBALS['_shopify_variant_id_cache'][$variantBarcode] = $variantId;
                    }
                }
            }
        }
    }

    return $GLOBALS['_shopify_variant_id_cache'][$sku] ?? null;
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
        $sku = trim((string) ($line['sku'] ?? ''));
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
