<?php
/**
 * Local stock management
 */

declare(strict_types=1);

/**
 * Update stock for a product by SKU or product_id
 *
 * @param string $identifier SKU or CRM product_id
 * @param int $quantityChange Negative to reduce, positive to add
 * @param string $byField 'sku' or 'product_id'
 * @return bool
 */
function updateStock(string $identifier, int $quantityChange, string $byField = 'sku'): bool
{
    if (!in_array($byField, ['sku', 'product_id'], true)) {
        $byField = 'sku';
    }

    $product = dbFetchOne(
        "SELECT id, sku, stock FROM products WHERE `{$byField}` = ? LIMIT 1",
        's',
        [$identifier]
    );

    if (!$product) {
        logWarning("updateStock: product not found ({$byField}={$identifier})");
        return false;
    }

    $newStock = max(0, (int) $product['stock'] + $quantityChange);

    dbQuery(
        'UPDATE products SET stock = ?, last_update = NOW() WHERE id = ?',
        'ii',
        [$newStock, (int) $product['id']]
    );

    logSync(sprintf(
        'Stock updated SKU=%s: %d → %d (change %+d)',
        $product['sku'],
        (int) $product['stock'],
        $newStock,
        $quantityChange
    ));

    return true;
}

/**
 * Set absolute stock level
 */
function setStock(string $sku, int $stock): bool
{
    $stock = max(0, $stock);
    $result = dbQuery(
        'UPDATE products SET stock = ?, last_update = NOW() WHERE sku = ?',
        'is',
        [$stock, $sku]
    );
    return (bool) $result;
}

/**
 * Get all products for sync
 * @return array<int, array<string, mixed>>
 */
function getAllProductsForSync(): array
{
    return dbFetchAll('SELECT id, product_id, sku, name, stock, price FROM products ORDER BY id ASC');
}

/**
 * Get products by SKU list
 * @param array<int, string> $skus
 * @return array<int, array<string, mixed>>
 */
function getProductsBySkus(array $skus): array
{
    if (count($skus) === 0) {
        return [];
    }

    $skus = array_values(array_unique(array_filter(array_map('trim', $skus))));
    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $types = str_repeat('s', count($skus));

    return dbFetchAll(
        "SELECT id, product_id, sku, name, stock, price FROM products WHERE sku IN ({$placeholders})",
        $types,
        $skus
    );
}
