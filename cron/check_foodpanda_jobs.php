<?php
/**
 * CRON (optional): Poll pending Foodpanda catalog jobs
 * Schedule: every 15 minutes
 * php /path/to/cron/check_foodpanda_jobs.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';

logCron('=== Foodpanda job status check started ===');

try {
    $jobs = dbFetchAll(
        "SELECT job_id, job_status FROM foodpanda_jobs
         WHERE job_status IN ('QUEUED', 'IN_PROGRESS')
         ORDER BY created_at DESC LIMIT 20"
    );
} catch (Throwable $e) {
    logCron('foodpanda_jobs table not found — run database migration in database.sql');
    if (php_sapi_name() === 'cli') {
        echo "Skip: foodpanda_jobs table missing\n";
    }
    exit(0);
}

$checked = 0;
foreach ($jobs as $job) {
    $jobId = (string) $job['job_id'];
    $result = getFoodpandaCatalogJobStatus($jobId);

    if ($result['success'] && is_array($result['data'])) {
        $status = (string) ($result['data']['job_status'] ?? $result['data']['status'] ?? '');
        saveFoodpandaJob($jobId, $status, 0);
        logCron("Job {$jobId}: {$status}");
        $checked++;
    } else {
        logWarning("Job {$jobId} check failed: " . ($result['error'] ?? ''));
    }
}

$msg = "Checked {$checked} Foodpanda catalog jobs";
logCron($msg);

if (php_sapi_name() === 'cli') {
    echo $msg . PHP_EOL;
} else {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'checked' => $checked]);
}

logCron('=== Foodpanda job status check finished ===');
