<?php
/**
 * SSH test runner — full sync pipeline with step-by-step output
 *
 * Usage:
 *   php cron/test_all_sync.php              # CRM → DB → Shopify → Foodpanda
 *   php cron/test_all_sync.php --shopify    # Shopify only (from DB)
 *   php cron/test_all_sync.php --crm        # CRM → DB only
 *   php cron/test_all_sync.php --foodpanda  # Foodpanda only (from DB)
 *   php cron/test_all_sync.php --sku=21026  # One SKU → Shopify only
 *   php cron/test_all_sync.php --help
 *
 * Hostinger:
 *   /usr/bin/php /home/u681832676/domains/blue-cobra-687212.hostingersite.com/public_html/cron/test_all_sync.php
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only. Run via SSH.');
}

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

$options = parseTestSyncOptions($argv);
if ($options['help']) {
    printTestSyncHelp();
    exit(0);
}

$started = microtime(true);
echo "========================================\n";
echo " Bombay Dry Fruits — Sync Test Runner\n";
echo " " . date('Y-m-d H:i:s') . " (" . APP_TIMEZONE . ")\n";
echo "========================================\n\n";

$exitCode = 0;

try {
    if ($options['sku'] !== '') {
        $exitCode = runTestSingleSku($options['sku']) ? 0 : 1;
    } elseif ($options['crm_only']) {
        $exitCode = runTestCrmOnly() ? 0 : 1;
    } elseif ($options['shopify_only']) {
        $exitCode = runTestShopifyOnly() ? 0 : 1;
    } elseif ($options['foodpanda_only']) {
        $exitCode = runTestFoodpandaOnly() ? 0 : 1;
    } else {
        $exitCode = runTestFullPipeline() ? 0 : 1;
    }
} catch (Throwable $e) {
    echo "\n[FATAL] " . $e->getMessage() . "\n";
    logError('test_all_sync: ' . $e->getMessage());
    $exitCode = 1;
}

$elapsed = round(microtime(true) - $started, 2);
echo "\n========================================\n";
echo $exitCode === 0 ? " DONE in {$elapsed}s\n" : " FINISHED WITH ERRORS ({$elapsed}s)\n";
echo " Check dashboard or logs table for details.\n";
echo "========================================\n";

exit($exitCode);

/**
 * @return array{help: bool, crm_only: bool, shopify_only: bool, foodpanda_only: bool, sku: string}
 */
function parseTestSyncOptions(array $argv): array
{
    $opts = [
        'help' => false,
        'crm_only' => false,
        'shopify_only' => false,
        'foodpanda_only' => false,
        'sku' => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $opts['help'] = true;
        } elseif ($arg === '--crm') {
            $opts['crm_only'] = true;
        } elseif ($arg === '--shopify') {
            $opts['shopify_only'] = true;
        } elseif ($arg === '--foodpanda') {
            $opts['foodpanda_only'] = true;
        } elseif (str_starts_with($arg, '--sku=')) {
            $opts['sku'] = normalizeSku(substr($arg, 6));
        } elseif ($arg !== '' && $opts['sku'] === '' && !str_starts_with($arg, '--')) {
            $opts['sku'] = normalizeSku($arg);
        }
    }

    return $opts;
}

function printTestSyncHelp(): void
{
    echo <<<HELP
Bombay Sync — test_all_sync.php

  php cron/test_all_sync.php
      Full pipeline: CRM API → MySQL → Shopify → Foodpanda

  php cron/test_all_sync.php --crm
      CRM → MySQL only

  php cron/test_all_sync.php --shopify
      MySQL → Shopify only

  php cron/test_all_sync.php --foodpanda
      MySQL → Foodpanda only

  php cron/test_all_sync.php --sku=21026
      One SKU: DB → Shopify (with location logs)

  php cron/test_all_sync.php 21026
      Same as --sku=21026

HELP;
}

function printStep(string $title): void
{
    echo "\n--- {$title} ---\n";
}

function runTestCrmOnly(): bool
{
    printStep('1/1 CRM → MySQL');
    $products = fetchCRMData();
    echo 'CRM products received: ' . count($products) . "\n";
    $saved = saveProductsToDB($products);
    echo "Saved to database: {$saved}\n";
    return $saved > 0;
}

function runTestShopifyOnly(): bool
{
    printStep('1/1 MySQL → Shopify');
    $count = (int) (dbFetchOne('SELECT COUNT(*) AS c FROM products')['c'] ?? 0);
    echo "Products in DB: {$count}\n";
    if ($count === 0) {
        echo "WARNING: DB empty. Run full test or --crm first.\n";
    }
    $result = syncShopifyInventory();
    echo "Shopify updated: {$result['success']}/{$result['total']}\n";
    echo "Not in Shopify (SKU mismatch): {$result['not_in_shopify']}\n";
    echo "API errors: {$result['api_errors']}\n";
    return $result['success'] > 0;
}

function runTestFoodpandaOnly(): bool
{
    printStep('1/1 MySQL → Foodpanda');
    $count = (int) (dbFetchOne('SELECT COUNT(*) AS c FROM products')['c'] ?? 0);
    echo "Products in DB: {$count}\n";
    $result = syncFoodpandaInventory();
    echo "Foodpanda SKUs sent: {$result['success']}, failed: {$result['failed']}\n";
    if (count($result['jobs']) > 0) {
        echo 'Job IDs: ' . implode(', ', $result['jobs']) . "\n";
    }
    return $result['failed'] === 0 || $result['success'] > 0;
}

function runTestSingleSku(string $sku): bool
{
    printStep("Single SKU → Shopify: {$sku}");
    $row = dbFetchOne('SELECT sku, name, stock, price FROM products WHERE sku = ? LIMIT 1', 's', [$sku]);
    if (!$row) {
        echo "ERROR: SKU not in database. Run full sync or --crm first.\n";
        return false;
    }
    echo "DB stock: {$row['stock']} | {$row['name']}\n";
    echo 'Primary location: ' . SHOPIFY_LOCATION_ID . "\n";
    echo 'Zero other locations: ' . (defined('SHOPIFY_ZERO_OTHER_LOCATIONS') && SHOPIFY_ZERO_OTHER_LOCATIONS ? 'yes' : 'no') . "\n";
    $ok = setShopifyInventoryBySku($sku, (int) $row['stock'], true);
    echo $ok ? "SUCCESS\n" : "FAILED — check logs\n";
    return $ok;
}

function runTestFullPipeline(): bool
{
    $ok = true;

    printStep('1/3 CRM → MySQL');
    try {
        $products = fetchCRMData();
        echo 'CRM products received: ' . count($products) . "\n";
        $saved = saveProductsToDB($products);
        echo "Saved to database: {$saved}\n";
        if ($saved === 0) {
            echo "WARNING: nothing saved.\n";
            $ok = false;
        }
    } catch (Throwable $e) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
        return false;
    }

    printStep('2/3 MySQL → Shopify');
    try {
        $shopify = syncShopifyInventory();
        echo "Shopify updated: {$shopify['success']}/{$shopify['total']}\n";
        echo "Not in Shopify: {$shopify['not_in_shopify']} | API errors: {$shopify['api_errors']}\n";
        if ($shopify['api_errors'] > 0) {
            $ok = false;
        }
    } catch (Throwable $e) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
        $ok = false;
    }

    printStep('3/3 MySQL → Foodpanda');
    try {
        if (!defined('FOODPANDA_API_TOKEN') || FOODPANDA_API_TOKEN === '' || FOODPANDA_API_TOKEN === 'your_bearer_token') {
            echo "SKIPPED: Foodpanda API token not configured in config.\n";
        } else {
            $fp = syncFoodpandaInventory();
            echo "Foodpanda SKUs sent: {$fp['success']}, failed: {$fp['failed']}\n";
            if ($fp['failed'] > 0) {
                $ok = false;
            }
        }
    } catch (Throwable $e) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
        $ok = false;
    }

    printStep('Summary');
    $meta = dbFetchOne('SELECT last_crm_fetch, last_shopify_sync, last_foodpanda_sync FROM sync_meta WHERE id = 1');
    echo 'Last CRM fetch:    ' . ($meta['last_crm_fetch'] ?? 'never') . "\n";
    echo 'Last Shopify sync: ' . ($meta['last_shopify_sync'] ?? 'never') . "\n";
    echo 'Last Foodpanda:    ' . ($meta['last_foodpanda_sync'] ?? 'never') . "\n";
    $total = (int) (dbFetchOne('SELECT COUNT(*) AS c FROM products')['c'] ?? 0);
    echo "Products in DB:    {$total}\n";

    return $ok;
}
