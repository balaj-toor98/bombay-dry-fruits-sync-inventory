<?php
/**
 * Shared dashboard layout helpers
 */

declare(strict_types=1);

function dashboardStyles(): string
{
    return <<<'CSS'
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #f5f5f5; color: #222; }
        h1 { margin-top: 0; }
        .subtitle { color: #555; margin-top: -8px; margin-bottom: 24px; }
        nav { margin-bottom: 24px; }
        nav a { display: inline-block; margin-right: 12px; padding: 8px 14px; background: #fff; border-radius: 6px; text-decoration: none; color: #0066cc; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        nav a.active { background: #0066cc; color: #fff; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        .card strong { display: block; font-size: 1.5rem; margin-top: 8px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
        th { background: #fafafa; }
        section { margin-bottom: 32px; }
        .tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .tabs a { padding: 10px 16px; background: #fff; border-radius: 6px; text-decoration: none; color: #333; box-shadow: 0 1px 3px rgba(0,0,0,.08); font-size: 14px; }
        .tabs a.active { background: #0066cc; color: #fff; }
        .tabs .count { opacity: .85; font-size: 13px; }
        .notice { background: #fff8e6; border: 1px solid #f0d98c; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 14px; }
        .empty { padding: 24px; text-align: center; color: #666; background: #fff; border-radius: 8px; }
        .section-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
        .section-header h2 { margin: 0; }
        .btn-export { display: inline-block; padding: 8px 14px; background: #fff; border: 1px solid #ccc; border-radius: 6px; text-decoration: none; color: #333; font-size: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .btn-export:hover { background: #f8f8f8; }
        .btn-update { padding: 6px 10px; background: #0066cc; border: none; border-radius: 4px; color: #fff; font-size: 13px; cursor: pointer; }
        .btn-update:hover { background: #0052a3; }
        .btn-update.secondary { background: #fff; color: #0066cc; border: 1px solid #0066cc; }
        .btn-update.secondary:hover { background: #f0f7ff; }
        .btn-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .flash-success { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2e7d32; }
        .flash-error { background: #ffebee; border: 1px solid #ef9a9a; color: #c62828; }
        .flash-warning { background: #fff8e6; border: 1px solid #f0d98c; color: #8a6d00; }
        .toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .search-form { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .search-form input[type="text"] { padding: 8px 12px; border: 1px solid #ccc; border-radius: 6px; min-width: 220px; font-size: 14px; }
        .search-form button { padding: 8px 14px; background: #0066cc; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .pagination-wrap { margin-top: 24px; display: flex; justify-content: center; }
        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            width: 100%;
            max-width: 520px;
            padding: 12px 20px;
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(15, 23, 42, 0.08);
        }
        .pagination-pages {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex: 1;
        }
        .pagination-nav {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 4px;
            text-decoration: none;
            color: #4338ca;
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
            transition: color 0.15s ease;
        }
        .pagination-nav:hover { color: #312e81; }
        .pagination-nav.disabled {
            color: #c7c9d9;
            pointer-events: none;
            cursor: default;
        }
        .pagination-page {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 10px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            color: #4338ca;
            background: #eef0ff;
            transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
        }
        .pagination-page:hover {
            background: #e0e4ff;
            color: #312e81;
        }
        .pagination-page.active {
            background: #5d5fef;
            color: #fff;
            box-shadow: 0 4px 12px rgba(93, 95, 239, 0.35);
            pointer-events: none;
        }
        .pagination-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 36px;
            color: #94a3b8;
            font-size: 16px;
            letter-spacing: 1px;
            user-select: none;
        }
        @media (max-width: 640px) {
            .pagination {
                max-width: 100%;
                padding: 10px 12px;
                gap: 8px;
            }
            .pagination-nav { font-size: 13px; }
            .pagination-page {
                min-width: 32px;
                height: 32px;
                border-radius: 8px;
                font-size: 13px;
            }
        }
        .inline-form { display: inline; margin: 0; }
        .actions-cell { white-space: nowrap; }
        .type-error { color: #c00; }
        .type-warning { color: #b8860b; }
        .type-sync, .type-webhook, .type-cron { color: #0066cc; }
CSS;
}

function dashboardNav(string $active = 'home'): void
{
    $links = [
        'home' => ['label' => 'Overview', 'href' => 'index.php'],
        'products' => ['label' => 'Products', 'href' => 'products.php'],
        'not-updated' => ['label' => 'Products Not Updated', 'href' => 'products-not-updated.php'],
    ];

    echo '<nav>';
    foreach ($links as $key => $link) {
        $class = $key === $active ? ' class="active"' : '';
        echo '<a' . $class . ' href="' . htmlspecialchars($link['href']) . '">' . htmlspecialchars($link['label']) . '</a>';
    }
    echo '</nav>';
}

function renderDashboardFlash(): void
{
    $msg = trim((string) ($_GET['msg'] ?? ''));
    if ($msg === '') {
        return;
    }

    $type = (string) ($_GET['type'] ?? 'success');
    if (!in_array($type, ['success', 'error', 'warning'], true)) {
        $type = 'success';
    }

    echo '<div class="flash flash-' . htmlspecialchars($type) . '">' . htmlspecialchars($msg) . '</div>';
}

/**
 * @param array<string, string> $hiddenFields
 */
function renderProductUpdateForm(
    string $sku,
    string $platform,
    string $redirect,
    string $label = 'Update',
    bool $secondary = false,
    array $hiddenFields = []
): void {
    $class = $secondary ? 'btn-update secondary' : 'btn-update';

    echo '<form class="inline-form" method="post" action="product-update.php" onsubmit="return confirm(' . htmlspecialchars(json_encode('Sync ' . $sku . ' to ' . $platform . '?'), ENT_QUOTES) . ');">';
    echo '<input type="hidden" name="sku" value="' . htmlspecialchars($sku) . '">';
    echo '<input type="hidden" name="platform" value="' . htmlspecialchars($platform) . '">';
    echo '<input type="hidden" name="redirect" value="' . htmlspecialchars($redirect) . '">';

    foreach ($hiddenFields as $name => $value) {
        if ($value === '') {
            continue;
        }
        echo '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">';
    }

    echo '<button type="submit" class="' . $class . '">' . htmlspecialchars($label) . '</button>';
    echo '</form>';
}

/**
 * @param array<int, array<string, mixed>> $products
 * @param array<string, mixed> $options show_actions, platform, redirect, show_last_update
 */
function renderProductTable(array $products, string $emptyMessage, array $options = []): void
{
    $showActions = (bool) ($options['show_actions'] ?? false);
    $platform = (string) ($options['platform'] ?? 'both');
    $redirect = (string) ($options['redirect'] ?? 'products.php');
    $showLastUpdate = (bool) ($options['show_last_update'] ?? false);
    $hiddenFields = is_array($options['hidden_fields'] ?? null) ? $options['hidden_fields'] : [];

    if (count($products) === 0) {
        echo '<div class="empty">' . htmlspecialchars($emptyMessage) . '</div>';
        return;
    }

    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Barcode / SKU</th><th>Product ID</th><th>Name</th><th>Stock</th><th>Price</th><th>Compare at</th>';
    if ($showLastUpdate) {
        echo '<th>Last update</th>';
    }
    if ($showActions) {
        echo '<th>Actions</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($products as $row) {
        $sku = (string) ($row['sku'] ?? '');
        echo '<tr>';
        echo '<td>' . htmlspecialchars($sku) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['product_id'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['name'] ?? '')) . '</td>';
        echo '<td>' . (int) ($row['stock'] ?? 0) . '</td>';
        echo '<td>' . htmlspecialchars(number_format((float) ($row['price'] ?? 0), 2)) . '</td>';
        echo '<td>' . htmlspecialchars(number_format((float) ($row['compare_at_price'] ?? 0), 2)) . '</td>';

        if ($showLastUpdate) {
            echo '<td>' . htmlspecialchars((string) ($row['last_update'] ?? '')) . '</td>';
        }

        if ($showActions) {
            echo '<td class="actions-cell"><div class="btn-group">';

            if ($platform === 'both') {
                renderProductUpdateForm($sku, 'shopify', $redirect, 'Shopify', true, $hiddenFields);
                renderProductUpdateForm($sku, 'foodpanda', $redirect, 'Foodpanda', true, $hiddenFields);
                renderProductUpdateForm($sku, 'both', $redirect, 'Both', false, $hiddenFields);
            } else {
                renderProductUpdateForm($sku, $platform, $redirect, 'Update', false, $hiddenFields);
            }

            echo '</div></td>';
        }

        echo '</tr>';
    }

    echo '</tbody></table>';
}

/**
 * @param array<int, string> $skus
 * @param array<string, mixed> $options
 */
function renderBulkUpdateForm(array $skus, array $options = []): void
{
    if (count($skus) === 0) {
        return;
    }

    $platform = (string) ($options['platform'] ?? 'both');
    $redirect = (string) ($options['redirect'] ?? 'products.php');
    $label = (string) ($options['label'] ?? 'Update all listed');
    $hiddenFields = is_array($options['hidden_fields'] ?? null) ? $options['hidden_fields'] : [];

    echo '<form method="post" action="product-update.php" class="inline-form" onsubmit="return confirm(' . htmlspecialchars(json_encode('Update ' . count($skus) . ' product(s)? This may take several minutes.'), ENT_QUOTES) . ');">';
    echo '<input type="hidden" name="platform" value="' . htmlspecialchars($platform) . '">';
    echo '<input type="hidden" name="redirect" value="' . htmlspecialchars($redirect) . '">';

    foreach ($hiddenFields as $name => $value) {
        if ($value === '') {
            continue;
        }
        echo '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">';
    }

    foreach ($skus as $sku) {
        echo '<input type="hidden" name="skus[]" value="' . htmlspecialchars($sku) . '">';
    }

    echo '<button type="submit" class="btn-update">' . htmlspecialchars($label) . '</button>';
    echo '</form>';
}

function buildPaginationItems(int $current, int $total, int $delta = 1): array
{
    if ($total <= 1) {
        return [];
    }

    $range = [];
    $items = [];
    $previous = null;

    for ($i = 1; $i <= $total; $i++) {
        if ($i === 1 || $i === $total || ($i >= $current - $delta && $i <= $current + $delta)) {
            $range[] = $i;
        }
    }

    foreach ($range as $pageNumber) {
        if ($previous !== null) {
            if ($pageNumber - $previous === 2) {
                $items[] = $previous + 1;
            } elseif ($pageNumber - $previous !== 1) {
                $items[] = '...';
            }
        }

        $items[] = $pageNumber;
        $previous = $pageNumber;
    }

    return $items;
}

function paginationUrl(string $baseUrl, array $query, int $page): string
{
    $query['page'] = $page;

    return $baseUrl . '?' . http_build_query($query);
}

function renderPagination(int $page, int $pages, string $baseUrl, array $query = []): void
{
    if ($pages <= 1) {
        return;
    }

    $items = buildPaginationItems($page, $pages);
    $prevPage = max(1, $page - 1);
    $nextPage = min($pages, $page + 1);
    $hasPrev = $page > 1;
    $hasNext = $page < $pages;

    echo '<nav class="pagination-wrap" aria-label="Pagination">';
    echo '<div class="pagination">';

    if ($hasPrev) {
        echo '<a class="pagination-nav pagination-back" href="' . htmlspecialchars(paginationUrl($baseUrl, $query, $prevPage)) . '" aria-label="Previous page">';
        echo '<span aria-hidden="true">&lsaquo;</span> Back';
        echo '</a>';
    } else {
        echo '<span class="pagination-nav pagination-back disabled" aria-disabled="true">';
        echo '<span aria-hidden="true">&lsaquo;</span> Back';
        echo '</span>';
    }

    echo '<div class="pagination-pages">';

    foreach ($items as $item) {
        if ($item === '...') {
            echo '<span class="pagination-ellipsis" aria-hidden="true">...</span>';
            continue;
        }

        $pageNumber = (int) $item;
        if ($pageNumber === $page) {
            echo '<span class="pagination-page active" aria-current="page">' . $pageNumber . '</span>';
            continue;
        }

        echo '<a class="pagination-page" href="' . htmlspecialchars(paginationUrl($baseUrl, $query, $pageNumber)) . '">';
        echo $pageNumber;
        echo '</a>';
    }

    echo '</div>';

    if ($hasNext) {
        echo '<a class="pagination-nav pagination-next" href="' . htmlspecialchars(paginationUrl($baseUrl, $query, $nextPage)) . '" aria-label="Next page">';
        echo 'Next <span aria-hidden="true">&rsaquo;</span>';
        echo '</a>';
    } else {
        echo '<span class="pagination-nav pagination-next disabled" aria-disabled="true">';
        echo 'Next <span aria-hidden="true">&rsaquo;</span>';
        echo '</span>';
    }

    echo '</div>';
    echo '</nav>';
}

/**
 * @param array<int, array<string, mixed>> $products
 */
function sendProductsCsvDownload(array $products, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        exit('Could not open output stream');
    }

    // UTF-8 BOM helps Excel open the file correctly
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Barcode / SKU', 'Product ID', 'Name', 'Stock', 'Price', 'Compare at']);

    foreach ($products as $row) {
        fputcsv($out, [
            (string) ($row['sku'] ?? ''),
            (string) ($row['product_id'] ?? ''),
            (string) ($row['name'] ?? ''),
            (int) ($row['stock'] ?? 0),
            number_format((float) ($row['price'] ?? 0), 2, '.', ''),
            number_format((float) ($row['compare_at_price'] ?? 0), 2, '.', ''),
        ]);
    }

    fclose($out);
    exit;
}

function productsNotUpdatedExportUrl(string $tab): string
{
    return '?tab=' . urlencode($tab) . '&export=csv';
}
