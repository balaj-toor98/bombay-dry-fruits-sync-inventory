<?php
/**
 * Webhook: Shopify order created
 *
 * NATIVE SHOPIFY ORDER (customer checkout on Shopify):
 *   1. Shopify deducts its own inventory
 *   2. This webhook deducts dashboard (MySQL) stock
 *   3. Sync updated stock to Foodpanda
 *
 * FOODPANDA-ORIGIN ORDER (Shopify order created by foodpanda_order.php):
 *   - Dashboard stock was already deducted in foodpanda_order.php
 *   - This webhook must NOT deduct dashboard stock again
 *
 * Register: Shopify Admin → Settings → Notifications → Webhooks
 *   Event: Order creation | Format: JSON
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
    // Foodpanda-originated Shopify orders: no dashboard deduction
    $skipReason = shouldSkipShopifyOrderStockDeduction($payload);
    if ($skipReason !== null) {
        logWebhook(sprintf(
            'Shopify order #%s skipped (reason=%s) — dashboard stock unchanged',
            $shopifyOrderId,
            $skipReason
        ));
        http_response_code(200);
        echo json_encode(['status' => 'skipped', 'reason' => $skipReason]);
        exit;
    }

    $lineItems = parseShopifyOrderLineItems($payload);

    if (count($lineItems) === 0) {
        logWarning(
            'Shopify webhook: no SKUs in order #' . $shopifyOrderId . '. Lines: '
            . summarizeShopifyOrderLinesWithoutSku($payload)
        );
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'message' => 'No SKU line items']);
        exit;
    }

    // Native Shopify order — claim so duplicate webhook retries do not deduct twice
    if (!claimOrderProcessing('shopify', $shopifyOrderId)) {
        logWebhook('Shopify order #' . $shopifyOrderId . ' already processed — skipping duplicate webhook');
        http_response_code(200);
        echo json_encode(['status' => 'skipped', 'reason' => 'duplicate']);
        exit;
    }

    // 1) Deduct dashboard stock
    $affectedSkus = deductOrderStock($lineItems);

    // 2) Sync inventory to Foodpanda
    $products = getProductsBySkus($affectedSkus);
    $syncResult = syncFoodpandaInventory($products);

    logWebhook(sprintf(
        'Native Shopify order %s: dashboard stock reduced, Foodpanda sync %d SKUs',
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
