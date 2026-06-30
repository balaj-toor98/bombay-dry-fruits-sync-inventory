<?php
/**
 * Dashboard session authentication (replaces HTTP Basic Auth popup)
 */

declare(strict_types=1);

if (!defined('DASHBOARD_ENABLED')) {
    require_once dirname(__DIR__) . '/helpers/bootstrap.php';
}

function dashboardStartSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_name('bombay_dashboard');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function dashboardIsLoggedIn(): bool
{
    dashboardStartSession();

    return !empty($_SESSION['dashboard_auth']) && $_SESSION['dashboard_auth'] === true;
}

function dashboardLogin(string $username, string $password): bool
{
    dashboardStartSession();

    $validUser = hash_equals(DASHBOARD_USER, $username)
        && hash_equals(DASHBOARD_PASS, $password);

    if (!$validUser) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['dashboard_auth'] = true;
    $_SESSION['dashboard_user'] = DASHBOARD_USER;

    return true;
}

function dashboardLogout(): void
{
    dashboardStartSession();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Safe redirect target after login (dashboard paths only)
 */
function dashboardSafeRedirect(string $redirect): string
{
    $redirect = trim($redirect);

    if ($redirect === '') {
        return 'index.php';
    }

    if (str_contains($redirect, '://') || str_starts_with($redirect, '//')) {
        return 'index.php';
    }

    if (str_starts_with($redirect, '/dashboard/')) {
        return $redirect;
    }

    if (preg_match('/^[a-z0-9_\-]+\.php(\?[^\s]*)?$/i', $redirect)) {
        return $redirect;
    }

    return 'index.php';
}

if (!DASHBOARD_ENABLED) {
    http_response_code(404);
    exit('Dashboard disabled');
}

// Skip auth check on login / logout pages
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
if (in_array($script, ['login.php', 'logout.php'], true)) {
    return;
}

if (!dashboardIsLoggedIn()) {
    $redirect = (string) ($_SERVER['REQUEST_URI'] ?? '/dashboard/index.php');
    header('Location: login.php?redirect=' . urlencode($redirect));
    exit;
}
