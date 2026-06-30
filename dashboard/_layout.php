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
        .type-error { color: #c00; }
        .type-warning { color: #b8860b; }
        .type-sync, .type-webhook, .type-cron { color: #0066cc; }
CSS;
}

function dashboardNav(string $active = 'home'): void
{
    $links = [
        'home' => ['label' => 'Overview', 'href' => 'index.php'],
        'not-updated' => ['label' => 'Products Not Updated', 'href' => 'products-not-updated.php'],
    ];

    echo '<nav>';
    foreach ($links as $key => $link) {
        $class = $key === $active ? ' class="active"' : '';
        echo '<a' . $class . ' href="' . htmlspecialchars($link['href']) . '">' . htmlspecialchars($link['label']) . '</a>';
    }
    echo '</nav>';
}

/**
 * @param array<int, array<string, mixed>> $products
 */
function renderProductTable(array $products, string $emptyMessage): void
{
    if (count($products) === 0) {
        echo '<div class="empty">' . htmlspecialchars($emptyMessage) . '</div>';
        return;
    }

    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Barcode / SKU</th><th>Product ID</th><th>Name</th><th>Stock</th><th>Price</th><th>Compare at</th>';
    echo '</tr></thead><tbody>';

    foreach ($products as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars((string) ($row['sku'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['product_id'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['name'] ?? '')) . '</td>';
        echo '<td>' . (int) ($row['stock'] ?? 0) . '</td>';
        echo '<td>' . htmlspecialchars(number_format((float) ($row['price'] ?? 0), 2)) . '</td>';
        echo '<td>' . htmlspecialchars(number_format((float) ($row['compare_at_price'] ?? 0), 2)) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
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
