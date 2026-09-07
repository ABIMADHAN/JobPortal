<?php
/**
 * queue-job-expiry.php
 * CLI / Cron script to automatically scan and close expired job postings.
 * Run manually or via Windows Task Scheduler / Linux Cron:
 *   php queue-job-expiry.php
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$pdo = get_db();
$closedCount = auto_close_expired_jobs($pdo);

echo "[" . date('Y-m-d H:i:s') . "] Auto-expiry check complete. Closed {$closedCount} expired job(s).\n";
