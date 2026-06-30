<?php
/**
 * Dashboard: CRM products not found in Shopify or Foodpanda
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

$report = getProductsNotUpdatedReport();
$shopifyMissing = $report['shopify'];
$foodpandaMissing = $report['foodpanda'];
$activeList = $tab === 'foodpanda' ? $foodpandaMissing : $shopifyMissing;

$meta = dbFetchOne('SELECT last_crm_fetch, last_shopify_sync, last_foodpanda_sync FROM sync_meta WHERE id = 1') ?? [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products Not Updated — Bombay Dry Fruits</title>
    <style><?= dashboardStyles() ?></style>
</head>
<body>
    <h1>Products Not Updated</h1>
    <p class="subtitle">CRM products that could not be matched on Shopify or Foodpanda</p>

    <?php dashboardNav('not-updated'); ?>

    <div class="grid">
        <div class="card">
            CRM products in DB
            <strong><?= (int) $report['total_crm'] ?></strong>
        </div>
        <div class="card">
            Missing in Shopify
            <strong><?= count($shopifyMissing) ?></strong>
        </div>
        <div class="card">
            Missing in Foodpanda
            <strong><?= count($foodpandaMissing) ?></strong>
        </div>
        <div class="card">
            Last CRM fetch
            <strong><?= htmlspecialchars($meta['last_crm_fetch'] ?? 'Never') ?></strong>
        </div>
    </div>

    <div class="notice">
        Lists are built from your local CRM cache (MySQL) compared live against each platform catalog.
        Shopify matches variant <strong>SKU</strong> or <strong>barcode</strong>.
        Foodpanda matches catalog <strong>SKU</strong> only.
        This page may take 1–2 minutes on first load.
    </div>

    <section>
        <div class="tabs">
            <a href="?tab=shopify" class="<?= $tab === 'shopify' ? 'active' : '' ?>">
                Not in Shopify <span class="count">(<?= count($shopifyMissing) ?>)</span>
            </a>
            <a href="?tab=foodpanda" class="<?= $tab === 'foodpanda' ? 'active' : '' ?>">
                Not in Foodpanda <span class="count">(<?= count($foodpandaMissing) ?>)</span>
            </a>
        </div>

        <?php if ($tab === 'shopify'): ?>
            <h2>Not in Shopify (<?= count($shopifyMissing) ?>)</h2>
            <?php renderProductTable(
                $shopifyMissing,
                'All CRM products have a matching Shopify variant (SKU or barcode).'
            ); ?>
        <?php else: ?>
            <h2>Not in Foodpanda (<?= count($foodpandaMissing) ?>)</h2>
            <?php renderProductTable(
                $foodpandaMissing,
                'All CRM products exist in the Foodpanda catalog.'
            ); ?>
        <?php endif; ?>
    </section>
</body>
</html>
