<?php
/**
 * Redirect root /login.php to dashboard login
 */
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'dashboard/login.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target);
exit;
