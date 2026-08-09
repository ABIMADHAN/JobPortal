<?php
/**
 * db_queue_migration.php
 * Migrates the database to create the email_queue table if it does not exist.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

try {
    $pdo = get_db();
    $sql = "CREATE TABLE IF NOT EXISTS email_queue (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        recipient       VARCHAR(255) NOT NULL,
        subject         VARCHAR(255) NOT NULL,
        body            LONGTEXT     NOT NULL,
        status          ENUM('pending', 'processing', 'sent', 'failed') NOT NULL DEFAULT 'pending',
        attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_error      TEXT         DEFAULT NULL,
        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at    DATETIME     DEFAULT NULL,
        INDEX idx_status_created (status, created_at)
    ) ENGINE=InnoDB;";

    $pdo->exec($sql);
    echo "Migration successful: email_queue table is ready.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
