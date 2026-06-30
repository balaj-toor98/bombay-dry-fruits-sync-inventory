<?php
/**
 * Example configuration — copy and fill in real values.
 *
 * HOSTINGER (Git auto-deploy): copy to ../private/config.php (above public_html).
 * LOCAL: copy to config/config.php
 *
 * Do NOT commit real config.php to GitHub.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_ENV', 'production');
define('APP_TIMEZONE', 'Asia/Karachi');

// --- MySQL (Hostinger: use FULL names with u681832676_ prefix) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'u681832676_inventory_sync'); // exact name from hPanel → Databases
define('DB_USER', 'u681832676_inventory_sync'); // must match DB user you created
define('DB_PASS', 'PASTE_PASSWORD_FROM_HOSTINGER');
define('DB_CHARSET', 'utf8mb4');

// --- CRM (usually leave as-is) ---
define('CRM_STOCK_URL', 'http://95.216.16.119:8085/myapi/getonlinelocationstock');
define('CRM_TIMEOUT', 120);

// --- Shopify (replace with your store) ---
define('SHOPIFY_SHOP', 'your-store.myshopify.com');
define('SHOPIFY_ACCESS_TOKEN', 'shpat_xxxxxxxx');
define('SHOPIFY_API_VERSION', '2024-01');
define('SHOPIFY_LOCATION_ID', 12345678901);
// When true: set other locations to 0 so total stock = API value (fixes 50+20=70 multi-location issue)
define('SHOPIFY_ZERO_OTHER_LOCATIONS', true);
// Variant metafield for out-of-stock limit (dashboard CSV upload)
define('SHOPIFY_OOS_LIMIT_NAMESPACE', 'custom');
define('SHOPIFY_OOS_LIMIT_KEY', 'out_of_stock_limit');
define('SHOPIFY_OOS_LIMIT_TYPE', 'number_integer');

// --- Foodpanda Partner API ---
define('FOODPANDA_API_BASE', 'https://foodpanda.partner.deliveryhero.io');
define('FOODPANDA_CHAIN_ID', 'your-chain-uuid');
define('FOODPANDA_VENDOR_ID', 'your-vendor-id');
define('FOODPANDA_API_TOKEN', 'your_bearer_token');
define('FOODPANDA_WEBHOOK_SECRET', 'your_webhook_secret');
define('FOODPANDA_SALES_BUFFER', 1);
define('FOODPANDA_LOCALE', 'en_PK');
define('FOODPANDA_DEFAULT_MAX_SALES_QTY', 0);
define('FOODPANDA_MIN_REQUEST_INTERVAL_MS', 1200);
define('FOODPANDA_BULK_CHUNK', 100);

define('HTTP_TIMEOUT', 60);
define('HTTP_RETRY_MAX', 3);
define('HTTP_RETRY_DELAY_MS', 500);

define('LOG_TO_FILE', true);
define('LOG_FILE_PATH', APP_ROOT . '/logs/app.log');

define('DASHBOARD_ENABLED', true);
define('DASHBOARD_USER', 'admin');
define('DASHBOARD_PASS', 'change_me');

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

date_default_timezone_set(APP_TIMEZONE);
