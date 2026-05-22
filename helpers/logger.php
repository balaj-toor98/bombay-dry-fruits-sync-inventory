<?php
/**
 * Error and activity logging (database + optional file)
 */

declare(strict_types=1);

/**
 * Write log entry to database and optionally to file
 */
function writeLog(string $type, string $message): void
{
    $type = substr($type, 0, 32);
    $message = substr($message, 0, 65535);

    try {
        dbQuery(
            'INSERT INTO logs (`type`, `message`) VALUES (?, ?)',
            'ss',
            [$type, $message]
        );
    } catch (Throwable $e) {
        error_log('Failed to write DB log: ' . $e->getMessage());
    }

    if (LOG_TO_FILE) {
        $dir = dirname(LOG_FILE_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] [%s] %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($type),
            $message
        );
        @file_put_contents(LOG_FILE_PATH, $line, FILE_APPEND | LOCK_EX);
    }
}

function logInfo(string $message): void
{
    writeLog('info', $message);
}

function logWarning(string $message): void
{
    writeLog('warning', $message);
}

function logError(string $message): void
{
    writeLog('error', $message);
}

function logSync(string $message): void
{
    writeLog('sync', $message);
}

function logWebhook(string $message): void
{
    writeLog('webhook', $message);
}

function logCron(string $message): void
{
    writeLog('cron', $message);
}
