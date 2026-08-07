<?php
/**
 * download-resume.php
 * Streams an applicant's resume to the recruiter who owns the job they applied to.
 * Files live outside the web-servable path logic on purpose: the filename in the
 * database is never trusted directly, only its basename inside uploads/.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_role('recruiter');

$pdo = get_db();
$company = get_owned_company($pdo, (int) current_user_id());

$applicationId = (int) query('application_id');

$stmt = $pdo->prepare(
    'SELECT sp.resume_path, sp.resume_original_name
     FROM applications a
     INNER JOIN jobs j ON a.job_id = j.id
     INNER JOIN student_profiles sp ON sp.user_id = a.student_id
     WHERE a.id = :app_id AND j.company_id = :company_id
     LIMIT 1'
);
$stmt->execute([':app_id' => $applicationId, ':company_id' => $company['id']]);
$row = $stmt->fetch();

if (!$row || empty($row['resume_path'])) {
    flash('Resume not found.', 'error');
    redirect('recruiter-dashboard.php?tab=applicants');
}

$filePath = UPLOAD_DIR . basename($row['resume_path']);
if (!is_file($filePath)) {
    flash('The resume file is missing on the server.', 'error');
    redirect('recruiter-dashboard.php?tab=applicants');
}

$downloadName = basename($row['resume_original_name'] ?: $row['resume_path']);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
