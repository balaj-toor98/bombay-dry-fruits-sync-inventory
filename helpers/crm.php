<?php
/**
 * CRM API integration — source of inventory truth
 */

declare(strict_types=1);

/**
 * Normalize CRM JSON — supports object or array wrapper from API
 *
 * @return array<int, array<string, mixed>>
 */
function extractCrmOnlineLocationStock(?array $data, string $rawBody = ''): array
{
    if (!is_array($data)) {
        $snippet = substr(trim($rawBody), 0, 300);
        logError('CRM response is not JSON. Snippet: ' . $snippet);
        throw new RuntimeException('Invalid CRM response format (not JSON)');
    }

    // [{"onlineLocationStock": [...]}]
    if (isset($data[0]) && is_array($data[0]) && isset($data[0]['onlineLocationStock'])) {
        $list = $data[0]['onlineLocationStock'];
        return is_array($list) ? $list : [];
    }

    // {"onlineLocationStock": [...]}
    if (isset($data['onlineLocationStock']) && is_array($data['onlineLocationStock'])) {
        return $data['onlineLocationStock'];
    }

    $snippet = substr(trim($rawBody), 0, 300);
    logError('CRM response missing onlineLocationStock. Snippet: ' . $snippet);
    throw new RuntimeException('Invalid CRM response format');
}

/**
 * Fetch online location stock from CRM API
 *
 * @return array<int, array<string, mixed>>
 */
function fetchCRMData(): array
{
    logCron('Fetching inventory from CRM: ' . CRM_STOCK_URL);

    $response = httpGet(CRM_STOCK_URL, ['timeout' => CRM_TIMEOUT]);

    if (!$response['success']) {
        $msg = 'CRM fetch failed. HTTP ' . $response['status'] . ' — ' . ($response['error'] ?? $response['body']);
        logError($msg);
        throw new RuntimeException($msg);
    }

    $data = $response['json'];

    // CRM may return {"onlineLocationStock":[...]} OR [{"onlineLocationStock":[...]}]
    $stockList = extractCrmOnlineLocationStock($data, $response['body']);

    $products = [];
    foreach ($stockList as $item) {
        if (!is_array($item)) {
            continue;
        }

        $productId = trim((string) ($item['ProductId'] ?? ''));
        $sku = trim((string) ($item['ProductBarcode'] ?? ''));
        $name = trim((string) ($item['ProductName'] ?? ''));

        if ($productId === '' || $sku === '') {
            continue;
        }

        // Negative stock → 0
        $rawStock = (float) ($item['LocationStock'] ?? 0);
        $stock = max(0, (int) floor($rawStock));

        $price = round((float) ($item['ProductSalePrice'] ?? 0), 2);
        $compareAtPrice = round((float) ($item['ProductRetailPrice'] ?? 0), 2);

        $products[] = [
            'product_id' => $productId,
            'sku' => $sku,
            'name' => $name,
            'stock' => $stock,
            'price' => $price,
            'compare_at_price' => $compareAtPrice,
        ];
    }

    logCron('CRM returned ' . count($products) . ' products');
    return $products;
}

/**
 * Upsert CRM products into local MySQL cache
 *
 * @param array<int, array<string, mixed>> $products
 * @return int Number of rows saved/updated
 */
function saveProductsToDB(array $products): int
{
    if (count($products) === 0) {
        logWarning('saveProductsToDB: no products to save');
        return 0;
    }

    $db = getDB();
    $sql = 'INSERT INTO products (product_id, sku, name, stock, price, compare_at_price, last_update)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                sku = VALUES(sku),
                name = VALUES(name),
                stock = VALUES(stock),
                price = VALUES(price),
                compare_at_price = VALUES(compare_at_price),
                last_update = NOW()';

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $db->error);
    }

    $saved = 0;
    foreach ($products as $p) {
        $productId = $p['product_id'];
        $sku = $p['sku'];
        $name = $p['name'];
        $stock = (int) $p['stock'];
        $price = (float) $p['price'];
        $compareAtPrice = (float) ($p['compare_at_price'] ?? 0);

        $stmt->bind_param('sssidd', $productId, $sku, $name, $stock, $price, $compareAtPrice);

        if ($stmt->execute()) {
            $saved++;
        } else {
            logError('Failed to save product ' . $productId . ': ' . $stmt->error);
        }
    }

    $stmt->close();
    updateSyncMeta('last_crm_fetch');
    logSync("Saved {$saved} products to database");

    return $saved;
}

/**
 * Fetch CRM data, save to DB, optionally sync to Shopify and Foodpanda
 *
 * @return array{
 *   success: bool,
 *   saved: int,
 *   shopify: array<string, mixed>,
 *   foodpanda: array<string, mixed>,
 *   message: string
 * }
 */
function runCrmFetchPipeline(bool $syncShopify = true, bool $syncFoodpanda = true): array
{
    $products = fetchCRMData();
    $saved = saveProductsToDB($products);

    $shopifyResult = [
        'success' => 0,
        'skipped' => true,
        'price_updated' => 0,
        'not_in_shopify' => 0,
        'api_errors' => 0,
        'price_errors' => 0,
    ];
    $foodpandaResult = [
        'success' => 0,
        'skipped' => true,
    ];

    if ($syncShopify) {
        $shopifyResult = syncShopifyInventory();
        unset($shopifyResult['skipped']);
    }

    if ($syncFoodpanda) {
        $foodpandaResult = syncFoodpandaInventory();
        unset($foodpandaResult['skipped']);
    }

    $message = formatCrmFetchSummary($saved, $shopifyResult, $foodpandaResult, $syncShopify, $syncFoodpanda);
    logSync($message);

    return [
        'success' => true,
        'saved' => $saved,
        'shopify' => $shopifyResult,
        'foodpanda' => $foodpandaResult,
        'message' => $message,
    ];
}

/**
 * @param array<string, mixed> $shopifyResult
 * @param array<string, mixed> $foodpandaResult
 */
function formatCrmFetchSummary(
    int $saved,
    array $shopifyResult,
    array $foodpandaResult,
    bool $syncShopify,
    bool $syncFoodpanda
): string {
    $parts = [sprintf('CRM fetch complete: %d products saved', $saved)];

    if ($syncShopify) {
        $parts[] = sprintf(
            'Shopify %d stock + %d prices (%d not in store, %d stock errors, %d price errors)',
            (int) ($shopifyResult['success'] ?? 0),
            (int) ($shopifyResult['price_updated'] ?? 0),
            (int) ($shopifyResult['not_in_shopify'] ?? 0),
            (int) ($shopifyResult['api_errors'] ?? 0),
            (int) ($shopifyResult['price_errors'] ?? 0)
        );
    }

    if ($syncFoodpanda) {
        $parts[] = sprintf('Foodpanda %d sent', (int) ($foodpandaResult['success'] ?? 0));
    }

    return implode('. ', $parts) . '.';
}
