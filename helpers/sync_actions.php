<?php
/**
 * Dashboard-triggered product sync actions
 */

declare(strict_types=1);

/**
 * @return array{success: bool, message: string, shopify: ?array<string, mixed>, foodpanda: ?array<string, mixed>}
 */
function updateSingleProduct(string $sku, string $platform = 'both'): array
{
    $sku = normalizeSku($sku);
    $platform = strtolower(trim($platform));

    if (!in_array($platform, ['shopify', 'foodpanda', 'both'], true)) {
        $platform = 'both';
    }

    $row = dbFetchOne(
        'SELECT id, product_id, sku, name, stock, price, compare_at_price FROM products WHERE sku = ? LIMIT 1',
        's',
        [$sku]
    );

    if (!$row) {
        return [
            'success' => false,
            'message' => "SKU {$sku} not found in database. Run CRM fetch first.",
            'shopify' => null,
            'foodpanda' => null,
        ];
    }

    $shopifyResult = null;
    $foodpandaResult = null;
    $messages = [];

    if ($platform === 'shopify' || $platform === 'both') {
        loadShopifyInventoryCache(true);
        $shopifyResult = syncShopifyProductByBarcode(
            $sku,
            (int) $row['stock'],
            (float) $row['price'],
            (float) ($row['compare_at_price'] ?? 0),
            false
        );

        if ($shopifyResult['inventory'] === 'ok') {
            $messages[] = 'Shopify stock updated';
        } elseif ($shopifyResult['inventory'] === 'not_found') {
            $messages[] = 'Shopify: variant not found (check SKU/barcode)';
        } else {
            $messages[] = 'Shopify stock update failed';
        }

        if ($shopifyResult['price'] === 'ok') {
            $messages[] = 'Shopify price updated';
        } elseif ($shopifyResult['price'] === 'skipped' && $shopifyResult['inventory'] !== 'not_found') {
            $messages[] = 'Shopify price unchanged';
        } elseif ($shopifyResult['price'] === 'error') {
            $messages[] = 'Shopify price update failed';
        }
    }

    if ($platform === 'foodpanda' || $platform === 'both') {
        $foodpandaResult = syncFoodpandaInventory(getProductsBySkus([$sku]));

        if (($foodpandaResult['success'] ?? 0) > 0) {
            $messages[] = 'Foodpanda update queued';
        } else {
            $messages[] = 'Foodpanda update failed';
        }
    }

    $success = !str_contains(implode(' ', $messages), 'failed')
        && !str_contains(implode(' ', $messages), 'not found');

    logSync(sprintf('Dashboard update SKU=%s platform=%s: %s', $sku, $platform, implode('; ', $messages)));

    return [
        'success' => $success,
        'message' => implode('. ', $messages) . '.',
        'shopify' => $shopifyResult,
        'foodpanda' => $foodpandaResult,
    ];
}

/**
 * @param array<int, string> $skus
 * @return array{success: int, failed: int, messages: array<int, string>}
 */
function updateMultipleProducts(array $skus, string $platform = 'both'): array
{
    $skus = array_values(array_unique(array_filter(array_map('normalizeSku', $skus))));
    $platform = strtolower(trim($platform));

    $shopifyOk = 0;
    $shopifyFail = 0;
    $foodpandaOk = 0;
    $foodpandaFail = 0;
    $messages = [];

    if ($platform === 'shopify' || $platform === 'both') {
        loadShopifyInventoryCache(true);

        foreach ($skus as $sku) {
            $row = dbFetchOne(
                'SELECT sku, stock, price, compare_at_price FROM products WHERE sku = ? LIMIT 1',
                's',
                [$sku]
            );

            if (!$row) {
                $shopifyFail++;
                continue;
            }

            $result = syncShopifyProductByBarcode(
                $sku,
                (int) $row['stock'],
                (float) $row['price'],
                (float) ($row['compare_at_price'] ?? 0),
                false
            );

            if ($result['inventory'] === 'ok') {
                $shopifyOk++;
            } else {
                $shopifyFail++;
            }

            usleep(250000);
        }

        $messages[] = "Shopify: {$shopifyOk} updated, {$shopifyFail} failed/not found";
    }

    if ($platform === 'foodpanda' || $platform === 'both') {
        $products = getProductsBySkus($skus);
        $foundSkus = array_map(static fn(array $p): string => normalizeSku((string) $p['sku']), $products);
        $missingCount = count(array_diff($skus, $foundSkus));

        if (count($products) > 0) {
            $fpResult = syncFoodpandaInventory($products);
            $foodpandaOk = (int) ($fpResult['success'] ?? 0);
            $foodpandaFail = (int) ($fpResult['failed'] ?? 0) + $missingCount;
            $messages[] = 'Foodpanda: ' . $foodpandaOk . ' queued, ' . $foodpandaFail . ' failed/missing';
        } else {
            $foodpandaFail = count($skus);
            $messages[] = 'Foodpanda: no matching products in database';
        }
    }

    $success = $shopifyOk + $foodpandaOk;
    $failed = $shopifyFail + $foodpandaFail;

    logSync(sprintf(
        'Dashboard bulk update platform=%s skus=%d success=%d failed=%d',
        $platform,
        count($skus),
        $success,
        $failed
    ));

    return [
        'success' => $success,
        'failed' => $failed,
        'messages' => $messages,
    ];
}

/**
 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
 */
function getDashboardProducts(string $search = '', int $page = 1, int $perPage = 50): array
{
    $page = max(1, $page);
    $perPage = max(10, min(200, $perPage));
    $offset = ($page - 1) * $perPage;
    $search = trim($search);

    if ($search !== '') {
        $like = '%' . $search . '%';
        $total = (int) (dbFetchOne(
            'SELECT COUNT(*) AS c FROM products WHERE sku LIKE ? OR name LIKE ?',
            'ss',
            [$like, $like]
        )['c'] ?? 0);

        $items = dbFetchAll(
            'SELECT id, product_id, sku, name, stock, price, compare_at_price, last_update
             FROM products
             WHERE sku LIKE ? OR name LIKE ?
             ORDER BY sku ASC
             LIMIT ? OFFSET ?',
            'ssii',
            [$like, $like, $perPage, $offset]
        );
    } else {
        $total = (int) (dbFetchOne('SELECT COUNT(*) AS c FROM products')['c'] ?? 0);
        $items = dbFetchAll(
            'SELECT id, product_id, sku, name, stock, price, compare_at_price, last_update
             FROM products
             ORDER BY sku ASC
             LIMIT ? OFFSET ?',
            'ii',
            [$perPage, $offset]
        );
    }

    $pages = max(1, (int) ceil($total / $perPage));

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
    ];
}
