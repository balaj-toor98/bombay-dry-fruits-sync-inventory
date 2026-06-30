<?php
/**
 * Shared dashboard auth guard
 */

declare(strict_types=1);

if (!defined('DASHBOARD_ENABLED')) {
    require_once dirname(__DIR__) . '/helpers/bootstrap.php';
}

if (!DASHBOARD_ENABLED) {
    http_response_code(404);
    exit('Dashboard disabled');
}

if (!isset($_SERVER['PHP_AUTH_USER'])
    || $_SERVER['PHP_AUTH_USER'] !== DASHBOARD_USER
    || $_SERVER['PHP_AUTH_PW'] !== DASHBOARD_PASS
) {
    header('WWW-Authenticate: Basic realm="Bombay Sync Dashboard"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Authentication required';
    exit;
}
