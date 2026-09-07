<?php
/**
 * download-resume.php
 * Streams an applicant's resume to the recruiter or owning student.
 * Supports mode=download (attachment) and mode=preview (inline).
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();

$pdo = get_db();
$userId = (int) current_user_id();
$role = (string) current_user_role();

$applicationId = (int) query('application_id');
$mode = query('mode') === 'preview' ? 'inline' : 'attachment';

$row = null;

if ($role === 'recruiter') {
    $company = get_owned_company($pdo, $userId);
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
} else {
    // Student downloading or previewing their own resume
    $stmt = $pdo->prepare(
        'SELECT resume_path, resume_original_name FROM student_profiles WHERE user_id = :uid LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
}

if (!$row || empty($row['resume_path'])) {
    flash('Resume not found.', 'error');
    redirect(dashboard_url());
}

$filePath = UPLOAD_DIR . basename($row['resume_path']);
if (!is_file($filePath)) {
    flash('The resume file is missing on the server.', 'error');
    redirect(dashboard_url());
}

$downloadName = basename($row['resume_original_name'] ?: $row['resume_path']);
$ext = strtolower(pathinfo($downloadName, PATHINFO_EXTENSION));

$contentTypes = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$contentType = $contentTypes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $contentType);
header('Content-Disposition: ' . $mode . '; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
