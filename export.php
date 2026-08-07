<?php
/**
 * export.php
 * Downloads the signed-in user's pipeline as a CSV file.
 *   student   -> every application they've made
 *   recruiter -> every applicant across their jobs
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();

$pdo = get_db();
$userId = (int) current_user_id();

if (current_user_role() === 'recruiter') {
    $company = get_owned_company($pdo, $userId);

    $stmt = $pdo->prepare(
        'SELECT u.full_name, u.email, u.phone, j.title AS job_title, a.status,
                a.applied_at, a.interview_at, sp.education, sp.skills, a.notes
         FROM applications a
         INNER JOIN jobs j ON a.job_id = j.id
         INNER JOIN users u ON a.student_id = u.id
         LEFT JOIN student_profiles sp ON sp.user_id = u.id
         WHERE j.company_id = :company_id
         ORDER BY a.applied_at DESC'
    );
    $stmt->execute([':company_id' => $company['id']]);

    $filename = 'applicants-' . date('Y-m-d') . '.csv';
    $headings = ['Applicant', 'Email', 'Phone', 'Job', 'Status', 'Applied', 'Interview', 'Education', 'Skills', 'Notes'];
} else {
    $stmt = $pdo->prepare(
        'SELECT j.title AS job_title, c.company_name, j.job_type, j.work_mode, j.location,
                a.status, a.applied_at, a.interview_at
         FROM applications a
         INNER JOIN jobs j ON a.job_id = j.id
         INNER JOIN companies c ON j.company_id = c.id
         WHERE a.student_id = :uid
         ORDER BY a.applied_at DESC'
    );
    $stmt->execute([':uid' => $userId]);

    $filename = 'my-applications-' . date('Y-m-d') . '.csv';
    $headings = ['Job', 'Company', 'Type', 'Work Mode', 'Location', 'Status', 'Applied', 'Interview'];
}

$rows = $stmt->fetchAll(PDO::FETCH_NUM);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel reads accented characters correctly.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, $headings);
foreach ($rows as $row) {
    fputcsv($out, $row);
}

fclose($out);
exit;
