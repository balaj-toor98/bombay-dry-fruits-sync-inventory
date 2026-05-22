<?php
/**
 * Simple monitoring dashboard (optional)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

if (!DASHBOARD_ENABLED) {
    http_response_code(404);
    exit('Dashboard disabled');
}

// Basic HTTP auth
if (!isset($_SERVER['PHP_AUTH_USER'])
    || $_SERVER['PHP_AUTH_USER'] !== DASHBOARD_USER
    || $_SERVER['PHP_AUTH_PW'] !== DASHBOARD_PASS
) {
    header('WWW-Authenticate: Basic realm="Bombay Sync Dashboard"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Authentication required';
    exit;
}

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
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, sans-serif; margin: 0; padding: 24px; background: #f5f5f5; color: #222; }
        h1 { margin-top: 0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        .card strong { display: block; font-size: 1.5rem; margin-top: 8px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
        th { background: #fafafa; }
        .type-error { color: #c00; }
        .type-warning { color: #b8860b; }
        .type-sync, .type-webhook, .type-cron { color: #0066cc; }
        section { margin-bottom: 32px; }
    </style>
</head>
<body>
    <h1>Inventory Sync Dashboard</h1>
    <p>Bombay Dry Fruits — CRM ↔ Shopify ↔ Foodpanda</p>

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
