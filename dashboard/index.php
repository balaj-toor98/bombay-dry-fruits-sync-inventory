<?php
/**
 * Simple monitoring dashboard (optional)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

$productCount = (int) (dbFetchOne('SELECT COUNT(*) AS c FROM products')['c'] ?? 0);
$lowStock = dbFetchAll('SELECT sku, name, stock FROM products WHERE stock <= 5 ORDER BY stock ASC LIMIT 20');
$meta = dbFetchOne('SELECT last_crm_fetch, last_shopify_sync, last_foodpanda_sync FROM sync_meta WHERE id = 1') ?? [];
$recentLogs = dbFetchAll('SELECT type, message, created_at FROM logs ORDER BY id DESC LIMIT 50');

$fpJobs = [];
try {
    $fpJobs = dbFetchAll(
        'SELECT job_id, job_status, sku_count, updated_at FROM foodpanda_jobs ORDER BY id DESC LIMIT 10'
    );
} catch (Throwable $e) {
    // foodpanda_jobs table optional until migration applied
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bombay Dry Fruits — Sync Dashboard</title>
    <style><?= dashboardStyles() ?></style>
</head>
<body>
    <h1>Inventory Sync Dashboard</h1>
    <p class="subtitle">Bombay Dry Fruits — CRM ↔ Shopify ↔ Foodpanda</p>

    <?php dashboardNav('home'); ?>

    <div class="grid">
        <div class="card">
            Products in DB
            <strong><?= htmlspecialchars((string) $productCount) ?></strong>
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

    <section>
        <h2>Low stock (≤ 5)</h2>
        <table>
            <thead><tr><th>SKU</th><th>Name</th><th>Stock</th></tr></thead>
            <tbody>
            <?php if (count($lowStock) === 0): ?>
                <tr><td colspan="3">No low-stock items</td></tr>
            <?php else: ?>
                <?php foreach ($lowStock as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['sku']) ?></td>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= (int) $row['stock'] ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

    <?php if (count($fpJobs) > 0): ?>
    <section>
        <h2>Foodpanda catalog jobs</h2>
        <table>
            <thead><tr><th>Job ID</th><th>Status</th><th>SKUs</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($fpJobs as $job): ?>
                <tr>
                    <td><?= htmlspecialchars($job['job_id']) ?></td>
                    <td><?= htmlspecialchars($job['job_status']) ?></td>
                    <td><?= (int) $job['sku_count'] ?></td>
                    <td><?= htmlspecialchars($job['updated_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <section>
        <h2>Recent logs</h2>
        <table>
            <thead><tr><th>Time</th><th>Type</th><th>Message</th></tr></thead>
            <tbody>
            <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars($log['created_at']) ?></td>
                    <td class="type-<?= htmlspecialchars($log['type']) ?>"><?= htmlspecialchars($log['type']) ?></td>
                    <td><?= htmlspecialchars($log['message']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</body>
</html>
