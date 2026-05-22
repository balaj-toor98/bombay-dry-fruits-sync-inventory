<?php
/**
 * One-time config path diagnostic — DELETE after fixing.
 * Visit: https://yourdomain.com/check-config.php
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$appRoot = realpath(__DIR__) ?: __DIR__;

function getPaths(string $appRoot): array
{
    $paths = [];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'];
        $paths[] = $docRoot . '/../private/config.php';
        $paths[] = $docRoot . '/private/config.php';
    }
    $paths[] = $appRoot . '/../private/config.php';
    $paths[] = dirname($appRoot) . '/private/config.php';
    $paths[] = $appRoot . '/private/config.php';
    $paths[] = $appRoot . '/config/config.local.php';
    $paths[] = $appRoot . '/config/config.php';
    return array_values(array_unique($paths));
}

echo "=== Bombay Sync — Config diagnostic ===\n\n";
echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'n/a') . "\n";
echo "App root (__DIR__): {$appRoot}\n";
echo "Parent folder: " . dirname($appRoot) . "\n\n";
echo "Config file check:\n";

$found = null;
foreach (getPaths($appRoot) as $path) {
    $ok = is_file($path);
    $status = $ok ? 'OK - EXISTS' : 'missing';
    echo "  [{$status}] {$path}\n";
    $real = realpath($path);
    if ($real) {
        echo "           realpath: {$real}\n";
    }
    if ($ok && $found === null) {
        $found = $path;
    }
}

echo "\n";
if ($found) {
    echo "Config found at: {$found}\n";
    echo "Try dashboard again. If still failing, open that file and check for PHP typos.\n";
} else {
    echo "NO config file found.\n\n";
    echo "CREATE THIS FILE (copy from config/config.example.php):\n";
    echo "  " . dirname($appRoot) . "/private/config.php\n\n";
    echo "WRONG (inside public_html): public_html/private/config.php\n";
    echo "       Git deploy may remove it — use sibling folder above.\n";
}

echo "\nDelete check-config.php when done.\n";
