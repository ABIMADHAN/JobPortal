<?php
/**
 * live-stats.php
 * Tiny JSON endpoint that returns the portal's headline counters straight from
 * the database. live-stats.js polls it so the stat strips on the public pages
 * keep updating without a page reload.
 *
 *   GET live-stats.php  ->  {"open_jobs":12,"companies":4, ...}
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdo = get_db();

/** Every counter is a single scalar query, so this stays cheap enough to poll. */
$counters = [
    'open_jobs'   => 'SELECT COUNT(*) FROM jobs WHERE status = "open"',
    'vacancies'   => 'SELECT COALESCE(SUM(vacancy_count), 0) FROM jobs WHERE status = "open"',
    'companies'   => 'SELECT COUNT(*) FROM companies',
    'hiring'      => 'SELECT COUNT(DISTINCT company_id) FROM jobs WHERE status = "open"',
    'internships' => 'SELECT COUNT(*) FROM jobs WHERE status = "open" AND job_type = "internship"',
    'remote'      => 'SELECT COUNT(*) FROM jobs WHERE status = "open" AND work_mode = "remote"',
    'hybrid'      => 'SELECT COUNT(*) FROM jobs WHERE status = "open" AND work_mode = "hybrid"',
    'students'    => 'SELECT COUNT(*) FROM users WHERE role = "student"',
    'applications' => 'SELECT COUNT(*) FROM applications',
    'posted_today' => 'SELECT COUNT(*) FROM jobs WHERE status = "open" AND created_at >= CURDATE()',
    'posted_week' => 'SELECT COUNT(*) FROM jobs WHERE status = "open" AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
    'closing_soon' => 'SELECT COUNT(*) FROM jobs WHERE status = "open" AND deadline IS NOT NULL
                         AND deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)',
];

$out = [];
foreach ($counters as $key => $sql) {
    $out[$key] = (int) $pdo->query($sql)->fetchColumn();
}
$out['updated_at'] = date('c');

echo json_encode($out, JSON_THROW_ON_ERROR);
