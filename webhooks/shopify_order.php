<?php
/**
 * Webhook: Shopify order created → Reduce DB stock + sync Foodpanda inventory
 *
 * Register in Shopify Admin → Settings → Notifications → Webhooks:
 *   Event: Order creation
 *   Format: JSON
 *   URL: https://yourdomain.com/webhooks/shopify_order.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input') ?: '';

if ($rawBody === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$shopifyOrderId = (string) ($payload['id'] ?? $payload['order_number'] ?? 'unknown');
logWebhook('Shopify order webhook: #' . $shopifyOrderId);

try {
    // Skip orders we created from Foodpanda to avoid double stock deduction
    $tags = (string) ($payload['tags'] ?? '');
    if (str_contains(strtolower($tags), 'foodpanda')) {
        logWebhook('Skipping Foodpanda-imported order to prevent double stock reduction');
        http_response_code(200);
        echo json_encode(['status' => 'skipped', 'reason' => 'foodpanda_import']);
        exit;
    }

    $lineItems = parseShopifyOrderLineItems($payload);

    if (count($lineItems) === 0) {
        logWarning('Shopify webhook: no SKUs in order');
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'message' => 'No SKU line items']);
        exit;
    }

    // 1) Reduce stock in DB
    $affectedSkus = [];
    foreach ($lineItems as $item) {
        updateStock($item['sku'], -$item['quantity'], 'sku');
        $affectedSkus[] = $item['sku'];
    }

    // 2) Sync inventory to Foodpanda (bulk catalog update for affected SKUs only)
    $products = getProductsBySkus($affectedSkus);
    $syncResult = syncFoodpandaInventory($products);

    logWebhook(sprintf(
        'Shopify order %s: stock reduced, Foodpanda sync %d SKUs',
        $shopifyOrderId,
        $syncResult['success']
    ));

    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'shopify_order_id' => $shopifyOrderId,
        'items_processed' => count($lineItems),
        'foodpanda_sync' => $syncResult,
    ]);
} catch (Throwable $e) {
    logError('Shopify webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
