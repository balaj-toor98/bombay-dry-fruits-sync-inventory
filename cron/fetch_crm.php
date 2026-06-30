<?php
/**
 * CRON: Daily CRM inventory fetch
 * Schedule: once per day (e.g. 0 2 * * *)
 * php /path/to/cron/fetch_crm.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

logCron('=== CRM fetch cron started ===');

try {
    $result = runCrmFetchPipeline(true, true);
    logCron($result['message']);

    if (php_sapi_name() === 'cli') {
        echo $result['message'] . PHP_EOL;
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'saved' => $result['saved'],
            'shopify' => $result['shopify'],
            'foodpanda' => $result['foodpanda'],
            'message' => $result['message'],
        ]);
    }
} catch (Throwable $e) {
    logError('CRM cron failed: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

logCron('=== CRM fetch cron finished ===');
