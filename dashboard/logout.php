<?php
/**
 * Dashboard logout
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';
require_once __DIR__ . '/_auth.php';

dashboardLogout();

header('Location: login.php');
exit;
