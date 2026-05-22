<?php
/**
 * Webhook: Foodpanda catalog update / export job results (async Step 3)
 *
 * Configure in Vendor Portal → Shop integrations → Webhook:
 *   URL: https://yourdomain.com/webhooks/foodpanda_catalog_job.php
 *   Secret: same as FOODPANDA_WEBHOOK_SECRET
 *
 * Receives per-SKU update status after PUT /catalog bulk jobs complete.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input') ?: '';
$headers = array_change_key_case(getallheaders() ?: [], CASE_LOWER);

if ($rawBody === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

if (!validateFoodpandaWebhook($headers, $rawBody)) {
    logWebhook('Foodpanda catalog job webhook: invalid signature');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

logWebhook('Foodpanda catalog job webhook: ' . substr($rawBody, 0, 800));

try {
    processFoodpandaCatalogJobWebhook($payload);

    http_response_code(200);
    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    logError('Foodpanda catalog job webhook error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
