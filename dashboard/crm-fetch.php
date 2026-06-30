<?php
/**
 * Dashboard: fetch latest data from CRM API and sync to platforms
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';
require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$redirect = trim((string) ($_POST['redirect'] ?? 'index.php'));
if ($redirect === '' || str_contains($redirect, '://') || str_starts_with($redirect, '//')) {
    $redirect = 'index.php';
}

$syncShopify = isset($_POST['sync_shopify']);
$syncFoodpanda = isset($_POST['sync_foodpanda']);

@set_time_limit(0);
@ini_set('memory_limit', '512M');

try {
    logCron('Dashboard CRM fetch started');
    $result = runCrmFetchPipeline($syncShopify, $syncFoodpanda);
    logCron('Dashboard CRM fetch: ' . $result['message']);

    $type = 'success';
    $msg = $result['message'];
} catch (Throwable $e) {
    logError('Dashboard CRM fetch failed: ' . $e->getMessage());
    $type = 'error';
    $msg = 'CRM fetch failed: ' . $e->getMessage();
}

$query = http_build_query([
    'msg' => $msg,
    'type' => $type,
]);

$separator = str_contains($redirect, '?') ? '&' : '?';
header('Location: ' . $redirect . $separator . $query);
exit;
