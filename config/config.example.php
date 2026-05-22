<?php
/**
 * Example configuration — copy to config.php and fill in values
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_ENV', 'production');
define('APP_TIMEZONE', 'Asia/Karachi');

define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456789_inventory');
define('DB_USER', 'u123456789_user');
define('DB_PASS', 'your_password');
define('DB_CHARSET', 'utf8mb4');

define('CRM_STOCK_URL', 'http://95.216.16.119:8085/myapi/getonlinelocationstock');
define('CRM_TIMEOUT', 120);

define('SHOPIFY_SHOP', 'bombay-dry-fruits.myshopify.com');
define('SHOPIFY_ACCESS_TOKEN', 'shpat_xxxxxxxx');
define('SHOPIFY_API_VERSION', '2024-01');
define('SHOPIFY_LOCATION_ID', 12345678901);
define('SHOPIFY_WEBHOOK_SECRET', 'whsec_xxxxxxxx');

define('FOODPANDA_API_BASE', 'https://foodpanda.partner.deliveryhero.io');
define('FOODPANDA_CHAIN_ID', '85649d5b-fc07-4c96-8ddb-91e03525ae35');
define('FOODPANDA_VENDOR_ID', '12345');
define('FOODPANDA_API_TOKEN', 'your_token');
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

date_default_timezone_set(APP_TIMEZONE);
