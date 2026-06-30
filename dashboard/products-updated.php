<?php
/**
 * Dashboard: CRM products matched on Shopify or Foodpanda (inventory + price can sync)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

@set_time_limit(300);
@ini_set('memory_limit', '512M');

$tab = (string) ($_GET['tab'] ?? 'shopify');
if (!in_array($tab, ['shopify', 'foodpanda'], true)) {
    $tab = 'shopify';
}

$page = max(1, (int) ($_GET['page'] ?? 1));

$export = (string) ($_GET['export'] ?? '');
if ($export === 'csv') {
    $report = getProductsUpdatedReport();
    $list = $tab === 'foodpanda' ? $report['foodpanda'] : $report['shopify'];
    $filename = sprintf('products-updated-%s-%s.csv', $tab, date('Y-m-d'));
    sendProductsCsvDownload($list, $filename);
}

$report = getProductsUpdatedReport();
$shopifyUpdated = $report['shopify'];
$foodpandaUpdated = $report['foodpanda'];
$activeList = $tab === 'foodpanda' ? $foodpandaUpdated : $shopifyUpdated;
$paged = paginateProductList($activeList, $page, 50);

$meta = dbFetchOne('SELECT last_crm_fetch, last_shopify_sync, last_foodpanda_sync FROM sync_meta WHERE id = 1') ?? [];

$redirectBase = 'products-updated.php?tab=' . urlencode($tab) . '&page=' . $paged['page'];
$hiddenFields = [
    'tab' => $tab,
    'page' => (string) $paged['page'],
];
$activeSkus = array_map(static fn(array $p): string => (string) $p['sku'], $paged['items']);

$paginationQuery = ['tab' => $tab];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products Updated — Bombay Dry Fruits</title>
    <style><?= dashboardStyles() ?></style>
</head>
<body>
    <h1>Products Updated</h1>
    <p class="subtitle">CRM products matched on Shopify or Foodpanda — inventory and price can sync</p>

    <?php dashboardNav('updated'); ?>
    <?php renderDashboardFlash(); ?>

    <div class="grid">
        <div class="card">
            CRM products in DB
            <strong><?= (int) $report['total_crm'] ?></strong>
        </div>
        <div class="card">
            Matched on Shopify
            <strong><?= count($shopifyUpdated) ?></strong>
        </div>
        <div class="card">
            Matched on Foodpanda
            <strong><?= count($foodpandaUpdated) ?></strong>
        </div>
        <div class="card">
            Last Shopify sync
            <strong><?= htmlspecialchars($meta['last_shopify_sync'] ?? 'Never') ?></strong>
        </div>
    </div>

    <div class="notice">
        These CRM products are found on each platform and receive inventory + price updates during sync.
        Shopify matches variant <strong>SKU</strong> or <strong>barcode</strong>.
        Foodpanda matches catalog <strong>SKU</strong> only.
        Stock and prices shown are from your CRM cache (MySQL).
    </div>

    <div class="dashboard-section">
        <div class="tabs">
            <a href="?tab=shopify" class="<?= $tab === 'shopify' ? 'active' : '' ?>">
                Updated on Shopify <span class="count">(<?= count($shopifyUpdated) ?>)</span>
            </a>
            <a href="?tab=foodpanda" class="<?= $tab === 'foodpanda' ? 'active' : '' ?>">
                Updated on Foodpanda <span class="count">(<?= count($foodpandaUpdated) ?>)</span>
            </a>
        </div>

        <?php if ($tab === 'shopify'): ?>
            <div class="section-header">
                <h2>
                    Updated on Shopify (<?= count($shopifyUpdated) ?>)
                    — page <?= (int) $paged['page'] ?> of <?= (int) $paged['pages'] ?>
                </h2>
                <div class="toolbar">
                    <a class="btn-export" href="<?= htmlspecialchars(productsUpdatedExportUrl('shopify')) ?>">Export CSV</a>
                    <?php renderBulkUpdateForm($activeSkus, [
                        'platform' => 'shopify',
                        'redirect' => $redirectBase,
                        'label' => 'Update all on page',
                        'hidden_fields' => $hiddenFields,
                    ]); ?>
                </div>
            </div>
            <?php renderProductTable(
                $paged['items'],
                'No CRM products matched on Shopify yet.',
                [
                    'show_actions' => true,
                    'platform' => 'shopify',
                    'redirect' => $redirectBase,
                    'hidden_fields' => $hiddenFields,
                ]
            ); ?>
        <?php else: ?>
            <div class="section-header">
                <h2>
                    Updated on Foodpanda (<?= count($foodpandaUpdated) ?>)
                    — page <?= (int) $paged['page'] ?> of <?= (int) $paged['pages'] ?>
                </h2>
                <div class="toolbar">
                    <a class="btn-export" href="<?= htmlspecialchars(productsUpdatedExportUrl('foodpanda')) ?>">Export CSV</a>
                    <?php renderBulkUpdateForm($activeSkus, [
                        'platform' => 'foodpanda',
                        'redirect' => $redirectBase,
                        'label' => 'Update all on page',
                        'hidden_fields' => $hiddenFields,
                    ]); ?>
                </div>
            </div>
            <?php renderProductTable(
                $paged['items'],
                'No CRM products matched on Foodpanda yet.',
                [
                    'show_actions' => true,
                    'platform' => 'foodpanda',
                    'redirect' => $redirectBase,
                    'hidden_fields' => $hiddenFields,
                ]
            ); ?>
        <?php endif; ?>

        <?php renderPagination($paged['page'], $paged['pages'], 'products-updated.php', $paginationQuery); ?>
    </div>
</body>
</html>
