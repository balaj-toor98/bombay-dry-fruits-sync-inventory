<?php
/**
 * Webhook: Foodpanda order → Create Shopify order + reduce DB stock + sync Shopify inventory
 *
 * Register URL in Foodpanda Partner Portal:
 * https://yourdomain.com/webhooks/foodpanda_order.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input') ?: '';
$headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);

if ($rawBody === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

if (!validateFoodpandaWebhook($headers, $rawBody)) {
    logWebhook('Foodpanda webhook: invalid signature');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

logWebhook('Foodpanda order webhook received: ' . substr($rawBody, 0, 500));

try {
    $orderId = parseFoodpandaOrderId($payload);
    $lineItems = parseFoodpandaOrderItems($payload);

    if (count($lineItems) === 0) {
        logWarning('Foodpanda webhook: no line items parsed');
        http_response_code(422);
        echo json_encode(['error' => 'No line items found']);
        exit;
    }

    // 1) Create order in Shopify
    $shopifyLineItems = [];
    foreach ($lineItems as $item) {
        $dbProduct = dbFetchOne('SELECT name, price FROM products WHERE sku = ?', 's', [$item['sku']]);
        $shopifyLineItems[] = [
            'sku' => $item['sku'],
            'quantity' => $item['quantity'],
            'title' => $item['title'] ?? ($dbProduct['name'] ?? $item['sku']),
            'price' => number_format((float) ($item['price'] ?? $dbProduct['price'] ?? 0), 2, '.', ''),
        ];
    }

    $orderResult = createShopifyOrder([
        'line_items' => $shopifyLineItems,
        'external_id' => $orderId,
        'note' => 'Foodpanda Order #' . $orderId,
        'tags' => 'foodpanda,webhook',
        'customer' => [
            'first_name' => 'Foodpanda',
            'last_name' => 'Order ' . $orderId,
        ],
    ]);

    if (!$orderResult['success']) {
        http_response_code(502);
        echo json_encode(['error' => $orderResult['error']]);
        exit;
    }

    // 2) Reduce stock in DB
    $affectedSkus = [];
    foreach ($lineItems as $item) {
        updateStock($item['sku'], -$item['quantity'], 'sku');
        $affectedSkus[] = $item['sku'];
    }

    // 3) Sync updated inventory to Shopify
    $products = getProductsBySkus($affectedSkus);
    syncShopifyInventory($products);

    logWebhook(sprintf(
        'Foodpanda order %s processed → Shopify order %s',
        $orderId,
        $orderResult['order_id'] ?? 'n/a'
    ));

    http_response_code(200);
    echo json_encode([
        'status' => 'ok',
        'foodpanda_order_id' => $orderId,
        'shopify_order_id' => $orderResult['order_id'],
        'items_processed' => count($lineItems),
    ]);
} catch (Throwable $e) {
    logError('Foodpanda webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
