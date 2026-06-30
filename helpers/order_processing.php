<?php
/**
 * Order stock rules (dashboard = MySQL products.stock)
 *
 * SHOPIFY NATIVE ORDER (customer checks out on Shopify):
 *   - Shopify deducts its own inventory automatically
 *   - shopify_order.php: deduct dashboard + sync Foodpanda
 *
 * FOODPANDA ORDER:
 *   - foodpanda_order.php: deduct dashboard + create Shopify order + sync Shopify inventory
 *   - shopify_order.php: MUST NOT deduct dashboard again for that Shopify order
 */

declare(strict_types=1);

/**
 * Try to claim an order for processing. Returns true only the first time per platform+order id.
 */
function claimOrderProcessing(string $platform, string $externalOrderId): bool
{
    $platform = strtolower(trim($platform));
    $externalOrderId = trim($externalOrderId);

    if (!in_array($platform, ['shopify', 'foodpanda'], true) || $externalOrderId === '') {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare(
        'INSERT IGNORE INTO processed_orders (platform, external_order_id) VALUES (?, ?)'
    );

    if (!$stmt) {
        throw new RuntimeException('processed_orders prepare failed: ' . $db->error);
    }

    $stmt->bind_param('ss', $platform, $externalOrderId);
    $stmt->execute();
    $claimed = $stmt->affected_rows > 0;
    $stmt->close();

    return $claimed;
}

/**
 * Release a claimed order so a failed webhook can be retried
 */
function releaseOrderProcessing(string $platform, string $externalOrderId): void
{
    $platform = strtolower(trim($platform));
    $externalOrderId = trim($externalOrderId);

    if ($externalOrderId === '') {
        return;
    }

    dbQuery(
        'DELETE FROM processed_orders WHERE platform = ? AND external_order_id = ? LIMIT 1',
        'ss',
        [$platform, $externalOrderId]
    );
}

/**
 * Check if an order was already processed
 */
function isOrderProcessed(string $platform, string $externalOrderId): bool
{
    $platform = strtolower(trim($platform));
    $externalOrderId = trim($externalOrderId);

    if ($externalOrderId === '') {
        return false;
    }

    $row = dbFetchOne(
        'SELECT id FROM processed_orders WHERE platform = ? AND external_order_id = ? LIMIT 1',
        'ss',
        [$platform, $externalOrderId]
    );

    return $row !== null;
}

/**
 * Mark a Shopify order as already handled (created from Foodpanda webhook)
 */
function markShopifyOrderProcessed(string $shopifyOrderId): void
{
    $shopifyOrderId = trim($shopifyOrderId);
    if ($shopifyOrderId === '') {
        return;
    }

    claimOrderProcessing('shopify', $shopifyOrderId);
}

/**
 * Detect Shopify orders we imported from Foodpanda (must not reduce dashboard stock again)
 */
function isShopifyFoodpandaImportOrder(array $payload): bool
{
    $tags = strtolower((string) ($payload['tags'] ?? ''));
    if (str_contains($tags, 'foodpanda')) {
        return true;
    }

    $note = strtolower((string) ($payload['note'] ?? ''));
    if (str_contains($note, 'foodpanda')) {
        return true;
    }

    $source = strtolower((string) ($payload['source_name'] ?? ''));
    if (str_contains($source, 'foodpanda')) {
        return true;
    }

    foreach ($payload['note_attributes'] ?? [] as $attr) {
        if (!is_array($attr)) {
            continue;
        }
        $name = strtolower((string) ($attr['name'] ?? ''));
        $value = strtolower((string) ($attr['value'] ?? ''));
        if (str_contains($name, 'foodpanda') || str_contains($value, 'foodpanda')) {
            return true;
        }
    }

    return false;
}

/**
 * Shopify order webhook: skip dashboard stock deduction?
 * Returns skip reason or null when stock should be updated (native Shopify order).
 */
function shouldSkipShopifyOrderStockDeduction(array $payload): ?string
{
    if (isShopifyFoodpandaImportOrder($payload)) {
        return 'foodpanda_import';
    }

    $shopifyOrderId = (string) ($payload['id'] ?? '');
    if ($shopifyOrderId !== '' && isOrderProcessed('shopify', $shopifyOrderId)) {
        return 'already_handled_by_foodpanda';
    }

    return null;
}

/**
 * Restore stock after a failed Foodpanda order handler
 *
 * @param array<int, array{sku: string, quantity: int}> $lineItems
 */
function restoreOrderStock(array $lineItems): void
{
    foreach ($lineItems as $item) {
        updateStock($item['sku'], $item['quantity'], 'sku');
    }
}

/**
 * Deduct stock for order line items
 *
 * @param array<int, array{sku: string, quantity: int}> $lineItems
 * @return array<int, string> affected SKUs
 */
function deductOrderStock(array $lineItems): array
{
    $affectedSkus = [];

    foreach ($lineItems as $item) {
        updateStock($item['sku'], -$item['quantity'], 'sku');
        $affectedSkus[] = $item['sku'];
    }

    return $affectedSkus;
}
