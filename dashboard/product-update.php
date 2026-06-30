<?php
/**
 * Dashboard: trigger product sync to Shopify / Foodpanda
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';
require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$platform = strtolower(trim((string) ($_POST['platform'] ?? 'both')));
if (!in_array($platform, ['shopify', 'foodpanda', 'both'], true)) {
    $platform = 'both';
}

$redirect = trim((string) ($_POST['redirect'] ?? 'products.php'));
if ($redirect === '' || str_contains($redirect, '://') || str_starts_with($redirect, '//')) {
    $redirect = 'products.php';
}

$skus = [];
if (isset($_POST['skus']) && is_array($_POST['skus'])) {
    foreach ($_POST['skus'] as $sku) {
        $sku = normalizeSku((string) $sku);
        if ($sku !== '') {
            $skus[] = $sku;
        }
    }
}

$singleSku = normalizeSku((string) ($_POST['sku'] ?? ''));
if ($singleSku !== '') {
    $skus[] = $singleSku;
}

$skus = array_values(array_unique($skus));

if (count($skus) === 0) {
    header('Location: ' . $redirect . '?msg=' . urlencode('No products selected.') . '&type=error');
    exit;
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

if (count($skus) === 1) {
    $result = updateSingleProduct($skus[0], $platform);
    $type = $result['success'] ? 'success' : 'error';
    $msg = $result['message'];
} else {
    $result = updateMultipleProducts($skus, $platform);
    $type = ($result['failed'] ?? 0) === 0 && ($result['success'] ?? 0) > 0 ? 'success' : 'warning';
    $msg = implode(' | ', $result['messages']);
}

$query = http_build_query(array_filter([
    'msg' => $msg,
    'type' => $type,
    'tab' => $_POST['tab'] ?? null,
    'q' => $_POST['q'] ?? null,
    'page' => $_POST['page'] ?? null,
], static fn($value) => $value !== null && $value !== ''));

$separator = str_contains($redirect, '?') ? '&' : '?';
header('Location: ' . $redirect . $separator . $query);
exit;
