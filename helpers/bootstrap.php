<?php
/**
 * Bootstrap: load config (survives Git deploy) and all helpers
 */

declare(strict_types=1);

$appRoot = dirname(__DIR__);

/**
 * Config paths checked in order (first existing file wins).
 * Put real secrets in ../private/config.php on Hostinger — Git deploy will NOT delete it.
 */
$configCandidates = [
    getenv('BOMBAY_CONFIG_PATH') ?: '',
    $appRoot . '/../private/config.php',
    $appRoot . '/config/config.local.php',
    $appRoot . '/config/config.php',
];

$configLoaded = false;
foreach ($configCandidates as $path) {
    if ($path !== '' && is_file($path)) {
        require_once $path;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Configuration missing.\n\n";
    echo "Create ONE of these files (recommended for Hostinger):\n";
    echo "  " . $appRoot . "/../private/config.php\n\n";
    echo "Copy from config/config.example.php and fill in your credentials.\n";
    echo "Git auto-deploy removes public_html/config/config.php because it is not in the repo.\n";
    exit(1);
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/shopify.php';
require_once __DIR__ . '/foodpanda.php';
