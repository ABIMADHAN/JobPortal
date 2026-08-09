<?php
/**
 * queue-worker.php
 * Background queue worker that processes pending emails from the `email_queue` table.
 *
 * Can be executed via:
 *   1. CLI: php queue-worker.php [--batch=10]
 *   2. Non-blocking HTTP / Internal Background Trigger
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail/send_mail.php';

function run_queue_worker(int $batchSize = 10): array
{
    $pdo = get_db();

    // 1. Fetch pending or retryable jobs
    $stmt = $pdo->prepare(
        "SELECT id, recipient, subject, body, attempts
         FROM email_queue
         WHERE status = 'pending' AND attempts < 3
         ORDER BY created_at ASC
         LIMIT :batchSize"
    );
    $stmt->bindValue(':batchSize', $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$jobs) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0];
    }

    $ids = array_column($jobs, 'id');
    $inClause = implode(',', array_map('intval', $ids));

    // 2. Mark fetched jobs as processing atomically
    $pdo->exec("UPDATE email_queue SET status = 'processing' WHERE id IN ($inClause)");

    $sentCount = 0;
    $failedCount = 0;

    // 3. Process each job using direct SMTP sendEmailImmediate()
    foreach ($jobs as $job) {
        $id = (int) $job['id'];
        $to = (string) $job['recipient'];
        $subject = (string) $job['subject'];
        $body = (string) $job['body'];
        $attempts = (int) $job['attempts'] + 1;

        $success = sendEmailImmediate($to, $subject, $body);

        if ($success) {
            $update = $pdo->prepare(
                "UPDATE email_queue
                 SET status = 'sent', attempts = :attempts, processed_at = NOW(), last_error = NULL
                 WHERE id = :id"
            );
            $update->execute([':attempts' => $attempts, ':id' => $id]);
            $sentCount++;
        } else {
            $failedCount++;
            $nextStatus = $attempts >= 3 ? 'failed' : 'pending';
            $update = $pdo->prepare(
                "UPDATE email_queue
                 SET status = :status, attempts = :attempts, last_error = 'SMTP Delivery Failed'
                 WHERE id = :id"
            );
            $update->execute([':status' => $nextStatus, ':attempts' => $attempts, ':id' => $id]);
        }
    }

    return [
        'processed' => count($jobs),
        'sent' => $sentCount,
        'failed' => $failedCount,
    ];
}

// If invoked directly from CLI or HTTP
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $batch = 10;
    if (PHP_SAPI === 'cli') {
        foreach ($argv ?? [] as $arg) {
            if (str_starts_with($arg, '--batch=')) {
                $batch = (int) substr($arg, 8);
            }
        }
    }

    $result = run_queue_worker(max(1, $batch));

    if (PHP_SAPI === 'cli') {
        echo sprintf(
            "Worker finish: Processed %d emails (Sent: %d, Failed: %d)\n",
            $result['processed'],
            $result['sent'],
            $result['failed']
        );
    } else {
        header('Content-Type: application/json');
        echo json_encode($result);
    }
}
