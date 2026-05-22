<?php
/**
 * CRM API integration — source of inventory truth
 */

declare(strict_types=1);

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
    if (!is_array($data) || !isset($data['onlineLocationStock']) || !is_array($data['onlineLocationStock'])) {
        logError('CRM response missing onlineLocationStock array');
        throw new RuntimeException('Invalid CRM response format');
    }

    $products = [];
    foreach ($data['onlineLocationStock'] as $item) {
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

        $products[] = [
            'product_id' => $productId,
            'sku' => $sku,
            'name' => $name,
            'stock' => $stock,
            'price' => $price,
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
    $sql = 'INSERT INTO products (product_id, sku, name, stock, price, last_update)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                sku = VALUES(sku),
                name = VALUES(name),
                stock = VALUES(stock),
                price = VALUES(price),
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

        $stmt->bind_param('sssids', $productId, $sku, $name, $stock, $price);

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
