<?php
/**
 * Dashboard login page
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

dashboardStartSession();

if (dashboardIsLoggedIn()) {
    header('Location: ' . dashboardSafeRedirect((string) ($_GET['redirect'] ?? 'index.php')));
    exit;
}

$error = '';
$redirect = dashboardSafeRedirect((string) ($_GET['redirect'] ?? 'index.php'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $redirect = dashboardSafeRedirect((string) ($_POST['redirect'] ?? $redirect));

    if (dashboardLogin($username, $password)) {
        header('Location: ' . $redirect);
        exit;
    }

    $error = 'Invalid username or password.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Bombay Dry Fruits</title>
    <style><?= dashboardLoginStyles() ?></style>
</head>
<body class="login-page">
    <div class="login-card">
        <h1>Bombay Dry Fruits</h1>
        <p class="login-subtitle">Inventory Sync Dashboard</p>

        <?php if ($error !== ''): ?>
            <div class="login-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" class="login-form">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">

            <label for="username">Username</label>
            <input type="text" id="username" name="username" autocomplete="username" required autofocus>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>

            <button type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
