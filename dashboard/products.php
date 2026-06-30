<?php
/**
 * Dashboard: all CRM products with sync actions
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

@set_time_limit(300);

$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$result = getDashboardProducts($search, $page, 50);
$products = $result['items'];

$redirect = 'products.php';
if ($search !== '') {
    $redirect .= '?q=' . urlencode($search) . '&page=' . $page;
} elseif ($page > 1) {
    $redirect .= '?page=' . $page;
}

$hiddenFields = [
    'q' => $search,
    'page' => (string) $page,
];

$meta = dbFetchOne('SELECT last_crm_fetch, last_shopify_sync, last_foodpanda_sync FROM sync_meta WHERE id = 1') ?? [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products — Bombay Dry Fruits</title>
    <style><?= dashboardStyles() ?></style>
</head>
<body>
    <h1>Products</h1>
    <p class="subtitle">CRM products — sync stock and prices to Shopify and Foodpanda</p>

    <?php dashboardNav('products'); ?>
    <?php renderDashboardFlash(); ?>

    <?php renderCrmFetchPanel($redirect); ?>

    <div class="grid">
        <div class="card">
            Total products
            <strong><?= (int) $result['total'] ?></strong>
        </div>
        <div class="card">
            Last CRM fetch
            <strong><?= htmlspecialchars($meta['last_crm_fetch'] ?? 'Never') ?></strong>
        </div>
        <div class="card">
            Last Shopify sync
            <strong><?= htmlspecialchars($meta['last_shopify_sync'] ?? 'Never') ?></strong>
        </div>
        <div class="card">
            Last Foodpanda sync
            <strong><?= htmlspecialchars($meta['last_foodpanda_sync'] ?? 'Never') ?></strong>
        </div>
    </div>

    <form class="search-form" method="get" action="products.php">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search SKU or product name">
        <button type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn-export" href="products.php">Clear</a>
        <?php endif; ?>
    </form>

    <section>
        <div class="section-header">
            <h2>
                Showing <?= count($products) ?> of <?= (int) $result['total'] ?>
                <?php if ($search !== ''): ?>
                    for “<?= htmlspecialchars($search) ?>”
                <?php endif; ?>
            </h2>
            <div class="toolbar">
                <?php
                $pageSkus = array_map(static fn(array $p): string => (string) $p['sku'], $products);
                renderBulkUpdateForm($pageSkus, [
                    'platform' => 'both',
                    'redirect' => $redirect,
                    'label' => 'Update all on page',
                    'hidden_fields' => $hiddenFields,
                ]);
                ?>
            </div>
        </div>

        <?php renderProductTable($products, 'No products found.', [
            'show_actions' => true,
            'platform' => 'both',
            'redirect' => $redirect,
            'show_last_update' => true,
            'hidden_fields' => $hiddenFields,
        ]); ?>

        <?php
        $paginationQuery = [];
        if ($search !== '') {
            $paginationQuery['q'] = $search;
        }
        renderPagination($result['page'], $result['pages'], 'products.php', $paginationQuery);
        ?>
    </section>
</body>
</html>
