<?php
/**
 * recruiter-dashboard.php
 * Recruiter workspace with modern SaaS pipeline & candidate management layout.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_role('recruiter');

$pdo = get_db();
ensure_meeting_link_column($pdo);
auto_close_expired_jobs($pdo);

$company = get_owned_company($pdo, (int) current_user_id());
$companyId = (int) $company['id'];

const JOB_TYPES = ['full-time' => 'Full-time', 'part-time' => 'Part-time', 'internship' => 'Internship', 'contract' => 'Contract'];
const WORK_MODES = ['onsite' => 'Onsite', 'hybrid' => 'Hybrid', 'remote' => 'Remote'];
const APPLICATION_STATUSES = [
  'applied' => 'Applied',
  'under_review' => 'Under Review',
  'shortlisted' => 'Shortlisted',
  'rejected' => 'Rejected',
  'hired' => 'Hired',
];

// Helper: Calculate Skill Match Score
function calculate_match_score(?string $candidateSkills, ?string $jobSkills): int
{
    if (empty($candidateSkills) || empty($jobSkills)) {
        return 75; // Default match baseline
    }
    
    $candSet = array_filter(array_map('trim', explode(',', strtolower($candidateSkills))));
    $jobSet = array_filter(array_map('trim', explode(',', strtolower($jobSkills))));
    
    if (empty($jobSet)) {
        return 85;
    }
    
    $matches = 0;
    foreach ($candSet as $cs) {
        foreach ($jobSet as $js) {
            if (str_contains($cs, $js) || str_contains($js, $cs)) {
                $matches++;
                break;
            }
        }
    }
    
    $ratio = $matches / count($jobSet);
    $score = (int) (60 + ($ratio * 38));
    return min(98, max(55, $score));
}

// ---------------------------------------------------------------
// POST Handlers
// ---------------------------------------------------------------
if (is_post()) {
  verify_csrf();
  $action = post('action');

  // ---- Create or update a job ----
  if ($action === 'save_job') {
    $jobId = (int) ($_POST['job_id'] ?? 0);

    $title = post('title');
    $description = post('description');
    $requirements = post('requirements');
    $skills = post('skills_required');
    $salary = post('salary');
    $location = post('location');
    $jobType = post('job_type');
    $workMode = post('work_mode');
    $vacancyCount = (int) ($_POST['vacancy_count'] ?? 1);
    $deadline = post('deadline');

    $formUrl = 'recruiter-dashboard.php?' . ($jobId ? 'edit=' . $jobId : 'new=1');

    $missing = first_missing(['job title' => $title, 'job description' => $description]);
    if ($missing !== '') {
      fail('Please enter a ' . $missing . '.', $_POST);
      redirect($formUrl);
    }
    if (!isset(JOB_TYPES[$jobType])) {
      fail('Invalid job type.', $_POST);
      redirect($formUrl);
    }
    if (!isset(WORK_MODES[$workMode])) {
      fail('Invalid work mode.', $_POST);
      redirect($formUrl);
    }
    if ($vacancyCount < 1) {
      fail('Vacancy count must be at least 1.', $_POST);
      redirect($formUrl);
    }
    if ($deadline !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline)) {
      fail('Deadline must be a valid date.', $_POST);
      redirect($formUrl);
    }

    $values = [
      ':title' => $title,
      ':description' => $description,
      ':requirements' => $requirements !== '' ? $requirements : null,
      ':skills_required' => $skills !== '' ? $skills : null,
      ':salary' => $salary !== '' ? $salary : null,
      ':job_type' => $jobType,
      ':location' => $location !== '' ? $location : null,
      ':work_mode' => $workMode,
      ':vacancy_count' => $vacancyCount,
      ':deadline' => $deadline !== '' ? $deadline : null,
    ];

    if ($jobId > 0) {
      $stmt = $pdo->prepare('SELECT id FROM jobs WHERE id = :id AND company_id = :company_id LIMIT 1');
      $stmt->execute([':id' => $jobId, ':company_id' => $companyId]);
      if (!$stmt->fetch()) {
        flash('Job not found, or you do not have permission to edit it.', 'error');
        redirect('recruiter-dashboard.php?tab=jobs');
      }

      $stmt = $pdo->prepare(
        'UPDATE jobs SET title = :title, description = :description, requirements = :requirements,
                        skills_required = :skills_required, salary = :salary, job_type = :job_type,
                        location = :location, work_mode = :work_mode, vacancy_count = :vacancy_count,
                        deadline = :deadline
         WHERE id = :id AND company_id = :company_id'
      );
      $stmt->execute($values + [':id' => $jobId, ':company_id' => $companyId]);
      flash('Job updated successfully.');
    } else {
      $stmt = $pdo->prepare(
        'INSERT INTO jobs (company_id, title, description, requirements, skills_required, salary,
                                    job_type, location, work_mode, vacancy_count, deadline, status)
                 VALUES (:company_id, :title, :description, :requirements, :skills_required, :salary,
                         :job_type, :location, :work_mode, :vacancy_count, :deadline, "open")'
      );
      $stmt->execute($values + [':company_id' => $companyId]);
      flash('Job posted successfully.');
    }

    redirect('recruiter-dashboard.php?tab=jobs');
  }

  // ---- Open / close / reopen a job ----
  if ($action === 'toggle_status') {
    $jobId = (int) ($_POST['job_id'] ?? 0);
    $targetStatus = post('status') === 'open' ? 'open' : 'closed';
    $newDeadline = post('deadline');

    if ($targetStatus === 'open') {
      if ($newDeadline === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDeadline)) {
        flash('Please select a valid future application deadline date to reopen.', 'error');
        redirect('recruiter-dashboard.php?tab=jobs&reopen=' . $jobId);
      }
      if (strtotime($newDeadline) < strtotime(date('Y-m-d'))) {
        flash('The extended deadline date must be today or in the future.', 'error');
        redirect('recruiter-dashboard.php?tab=jobs&reopen=' . $jobId);
      }

      $stmt = $pdo->prepare('UPDATE jobs SET status = "open", deadline = :deadline WHERE id = :id AND company_id = :company_id');
      $stmt->execute([':deadline' => $newDeadline, ':id' => $jobId, ':company_id' => $companyId]);
      flash('Job reopened successfully with extended deadline until ' . format_date($newDeadline) . '.');
    } else {
      $stmt = $pdo->prepare('UPDATE jobs SET status = "closed" WHERE id = :id AND company_id = :company_id');
      $stmt->execute([':id' => $jobId, ':company_id' => $companyId]);
      flash('Job closed.');
    }
    redirect('recruiter-dashboard.php?tab=jobs');
  }

  // ---- Delete a job ----
  if ($action === 'delete_job') {
    $jobId = (int) ($_POST['job_id'] ?? 0);

    $stmt = $pdo->prepare('DELETE FROM jobs WHERE id = :id AND company_id = :company_id');
    $stmt->execute([':id' => $jobId, ':company_id' => $companyId]);

    if ($stmt->rowCount() === 0) {
      flash('Job not found, or you do not have permission to delete it.', 'error');
    } else {
      flash('Job deleted.');
    }
    redirect('recruiter-dashboard.php?tab=jobs');
  }

  // ---- Update an applicant's status ----
  if ($action === 'update_application') {
    ensure_meeting_link_column($pdo);
    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $status = post('status');
    $notes = post('notes');
    $interviewAt = post('interview_at');
    $meetingLink = safe_url(post('meeting_link'));

    if (!isset(APPLICATION_STATUSES[$status])) {
      flash('Invalid status value.', 'error');
      redirect('recruiter-dashboard.php?tab=applicants');
    }

    $interviewValue = null;
    if (!in_array($status, ['rejected', 'withdrawn'], true) && $interviewAt !== '') {
      $ts = strtotime($interviewAt);
      if ($ts === false) {
        flash('Interview date is not a valid date and time.', 'error');
        redirect('recruiter-dashboard.php?tab=applicants&review=' . $applicationId);
      }
      $interviewValue = date('Y-m-d H:i:s', $ts);
    }

    $stmt = $pdo->prepare(
      'SELECT a.status, a.notes, a.interview_at, a.meeting_link
             FROM applications a
             INNER JOIN jobs j ON a.job_id = j.id
             WHERE a.id = :id AND j.company_id = :company_id LIMIT 1'
    );
    $stmt->execute([':id' => $applicationId, ':company_id' => $companyId]);
    $before = $stmt->fetch();

    $stmt = $pdo->prepare(
      'UPDATE applications a
             INNER JOIN jobs j ON a.job_id = j.id
             SET a.status = :status, a.notes = :notes, a.interview_at = :interview_at, a.meeting_link = :meeting_link
             WHERE a.id = :id AND j.company_id = :company_id'
    );
    $stmt->execute([
      ':status' => $status,
      ':notes' => $notes,
      ':interview_at' => $interviewValue,
      ':meeting_link' => $meetingLink !== '' ? $meetingLink : null,
      ':id' => $applicationId,
      ':company_id' => $companyId,
    ]);

    $changed = [];
    if ($before) {
      if ($before['status'] !== $status) {
        $changed[] = 'status';
      }
      if ((string) $before['interview_at'] !== (string) $interviewValue) {
        $changed[] = 'interview';
      }
      if (trim((string) $before['notes']) !== trim($notes)) {
        $changed[] = 'notes';
      }
    }

    if ($changed) {
      require_once __DIR__ . '/notifications.php';
      notify_application_updated($pdo, $applicationId, $changed);
      flash('Applicant updated. Notification email sent.');
    } else {
      flash('Applicant updated.');
    }

    redirect('recruiter-dashboard.php?tab=applicants');
  }

  // ---- Bulk Action on Candidates ----
  if ($action === 'bulk_action') {
    ensure_meeting_link_column($pdo);
    $appIds = $_POST['application_ids'] ?? [];
    $bulkStatus = post('bulk_status');
    $bulkNotes = post('bulk_notes');
    $bulkInterviewAt = post('bulk_interview_at');
    $bulkMeetingLink = safe_url(post('bulk_meeting_link'));

    if (!is_array($appIds) || empty($appIds)) {
      flash('Please select at least one candidate for bulk action.', 'error');
      redirect('recruiter-dashboard.php?tab=applicants');
    }
    if (!isset(APPLICATION_STATUSES[$bulkStatus])) {
      flash('Invalid status for bulk update.', 'error');
      redirect('recruiter-dashboard.php?tab=applicants');
    }

    $interviewValue = null;
    if (!in_array($bulkStatus, ['rejected', 'withdrawn'], true) && $bulkInterviewAt !== '') {
      $ts = strtotime($bulkInterviewAt);
      if ($ts !== false) {
        $interviewValue = date('Y-m-d H:i:s', $ts);
      }
    }

    $cleanIds = array_map('intval', $appIds);
    $hasNotes = $bulkNotes !== '' ? 1 : 0;
    $hasInterview = $interviewValue !== null ? 1 : 0;
    $hasMeetingLink = $bulkMeetingLink !== '' ? 1 : 0;

    $inPlaceholders = [];
    $execParams = [
      ':status' => $bulkStatus,
      ':has_notes' => $hasNotes,
      ':notes' => $bulkNotes,
      ':has_interview' => $hasInterview,
      ':interview_at' => $interviewValue,
      ':has_meeting_link' => $hasMeetingLink,
      ':meeting_link' => $bulkMeetingLink !== '' ? $bulkMeetingLink : null,
      ':company_id' => $companyId,
    ];

    foreach ($cleanIds as $idx => $id) {
      $pName = ':id_' . $idx;
      $inPlaceholders[] = $pName;
      $execParams[$pName] = $id;
    }

    $inClause = implode(',', $inPlaceholders);

    // Find matching application IDs for this recruiter's company
    $stmtValid = $pdo->prepare(
      "SELECT a.id FROM applications a
       INNER JOIN jobs j ON a.job_id = j.id
       WHERE a.id IN ({$inClause}) AND j.company_id = :company_id"
    );
    $stmtValid->execute([':company_id' => $companyId] + array_combine($inPlaceholders, $cleanIds));
    $validIds = $stmtValid->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($validIds)) {
      $stmt = $pdo->prepare(
        "UPDATE applications a
         INNER JOIN jobs j ON a.job_id = j.id
         SET a.status = :status,
             a.notes = CASE WHEN :has_notes = 1 THEN :notes ELSE a.notes END,
             a.interview_at = CASE WHEN :has_interview = 1 THEN :interview_at ELSE a.interview_at END,
             a.meeting_link = CASE WHEN :has_meeting_link = 1 THEN :meeting_link ELSE a.meeting_link END
         WHERE a.id IN ({$inClause}) AND j.company_id = :company_id"
      );
      $stmt->execute($execParams);
      $stmt->execute($execParams);

      require_once __DIR__ . '/notifications.php';
      foreach ($validIds as $appId) {
        $changed = ['status'];
        if ($hasNotes) $changed[] = 'notes';
        if ($hasInterview) $changed[] = 'interview';
        notify_application_updated($pdo, (int) $appId, $changed);
      }

      $count = count($validIds);
      flash("Bulk update complete: {$count} candidate(s) updated and notification emails queued.");
    } else {
      flash('No matching candidates found for update.', 'error');
    }

    redirect('recruiter-dashboard.php?tab=applicants');
  }

  redirect('recruiter-dashboard.php');
}

// ---------------------------------------------------------------
// GET: Fetch Dashboard Data
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
  'SELECT COUNT(*) AS total_jobs,
            SUM(CASE WHEN status = "open" THEN 1 ELSE 0 END) AS active_jobs,
            SUM(CASE WHEN status = "closed" THEN 1 ELSE 0 END) AS closed_jobs
     FROM jobs WHERE company_id = :company_id'
);
$stmt->execute([':company_id' => $companyId]);
$jobStats = $stmt->fetch() ?: [];

$stmt = $pdo->prepare(
  'SELECT COUNT(*) AS total_applicants,
            SUM(CASE WHEN a.status IN ("applied", "under_review") THEN 1 ELSE 0 END) AS pending_reviews,
            SUM(CASE WHEN a.status = "hired" THEN 1 ELSE 0 END) AS hired_candidates
     FROM applications a
     INNER JOIN jobs j ON a.job_id = j.id
     WHERE j.company_id = :company_id'
);
$stmt->execute([':company_id' => $companyId]);
$appStats = $stmt->fetch() ?: [];

// Jobs list
$stmt = $pdo->prepare(
  'SELECT j.*, (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applicant_count
     FROM jobs j
     WHERE j.company_id = :company_id
     ORDER BY j.created_at DESC'
);
$stmt->execute([':company_id' => $companyId]);
$jobs = $stmt->fetchAll();

// Applicants list with filters
$filterJobId = (int) query('job_id');
$filterStatus = query('status');
$filterQuery = trim(query('q'));

$where = ['j.company_id = :company_id'];
$params = [':company_id' => $companyId];

if ($filterJobId > 0) {
  $where[] = 'a.job_id = :job_id';
  $params[':job_id'] = $filterJobId;
}
if (!isset(APPLICATION_STATUSES[$filterStatus])) {
  $filterStatus = '';
}
if ($filterQuery !== '') {
  $where[] = '(u.full_name LIKE :q_name OR u.email LIKE :q_email OR j.title LIKE :q_title OR sp.skills LIKE :q_skills)';
  $params[':q_name'] = '%' . $filterQuery . '%';
  $params[':q_email'] = '%' . $filterQuery . '%';
  $params[':q_title'] = '%' . $filterQuery . '%';
  $params[':q_skills'] = '%' . $filterQuery . '%';
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
  "SELECT a.id AS application_id, a.status, a.notes, a.applied_at, a.interview_at, a.meeting_link,
            j.title AS job_title, j.skills_required,
            u.full_name, u.email, u.phone,
            sp.education, sp.skills, sp.bio, sp.resume_path, sp.resume_original_name
     FROM applications a
     INNER JOIN jobs j ON a.job_id = j.id
     INNER JOIN users u ON a.student_id = u.id
     LEFT JOIN student_profiles sp ON sp.user_id = u.id
     WHERE {$whereSql}
     ORDER BY a.applied_at DESC"
);
$stmt->execute($params);
$applicants = $stmt->fetchAll();

// Map Candidates Data
$candidateItems = [];
foreach ($applicants as $app) {
    $matchScore = calculate_match_score($app['skills'] ?? '', $app['skills_required'] ?? '');
    
    $badgeClass = match ($app['status']) {
        'under_review' => 'badge-sky',
        'shortlisted' => 'badge-purple',
        'hired' => 'badge-emerald',
        'rejected', 'withdrawn' => 'badge-rose',
        default => 'badge-dark',
    };
    
    $nextStep = 'Pending Review';
    if (!empty($app['interview_at'])) {
        $nextStep = 'Interview: ' . format_datetime($app['interview_at']);
    } elseif ($app['status'] === 'hired') {
        $nextStep = 'Onboarding Scheduled';
    } elseif ($app['status'] === 'rejected') {
        $nextStep = 'Rejection Email Sent';
    } elseif ($app['status'] === 'shortlisted') {
        $nextStep = 'Shortlisted for Screening';
    }

    $candidateItems[] = [
        'id' => $app['application_id'],
        'name' => $app['full_name'],
        'email' => $app['email'],
        'initials' => initials($app['full_name']),
        'role' => $app['job_title'],
        'applied' => 'Applied ' . format_date($app['applied_at']),
        'stage' => status_label($app['status']),
        'raw_status' => $app['status'],
        'stage_badge' => $badgeClass,
        'exp' => !empty($app['education']) ? $app['education'] : 'Applicant',
        'match' => $matchScore . '% Match',
        'next_step' => $nextStep,
        'action' => in_array($app['status'], ['rejected', 'withdrawn'], true) ? 'View Notes' : 'Review',
    ];
}

$interviews = upcoming_interviews($pdo, 15);

// Tab & Modal State
$tab = match (query('tab')) {
  'jobs' => 'jobs',
  'applicants' => 'applicants',
  default => 'pipeline',
};

$applicantsUrl = 'recruiter-dashboard.php?tab=applicants'
  . ($filterJobId > 0 ? '&job_id=' . $filterJobId : '')
  . ($filterStatus !== '' ? '&status=' . urlencode($filterStatus) : '');

$editJob = null;
$showJobDialog = isset($_GET['new']);
if (isset($_GET['edit'])) {
  $editId = (int) $_GET['edit'];
  foreach ($jobs as $job) {
    if ((int) $job['id'] === $editId) {
      $editJob = $job;
      $showJobDialog = true;
      $tab = 'jobs';
      break;
    }
  }
}

$reopenJob = null;
if (isset($_GET['reopen'])) {
  $reopenId = (int) $_GET['reopen'];
  foreach ($jobs as $job) {
    if ((int) $job['id'] === $reopenId) {
      $reopenJob = $job;
      $tab = 'jobs';
      break;
    }
  }
}

$reviewApplicant = null;
if (isset($_GET['review'])) {
  $reviewId = (int) $_GET['review'];
  foreach ($applicants as $applicant) {
    if ((int) $applicant['application_id'] === $reviewId) {
      $reviewApplicant = $applicant;
      $tab = 'applicants';
      break;
    }
  }
}

$formError = take_error();
$formOld = take_old();

$totalApplicants = (int) ($appStats['total_applicants'] ?? 0);
$hiredCandidates = (int) ($appStats['hired_candidates'] ?? 0);
$hireRate = $totalApplicants > 0 ? round(($hiredCandidates / $totalApplicants) * 100, 1) : 0;

$pageTitle = 'Recruiter Dashboard';
$topbarTitle = $company['company_name'] . ' · Hiring Pipeline';
$activeNav = match ($tab) {
  'jobs' => 'myjobs',
  'applicants' => 'applicants',
  default => 'dashboard',
};
$layout = 'app';

require __DIR__ . '/header.php';
?>

<style>
/* Modern Recruiter Dashboard Custom Styles */
:root {
  --brand-600: #4f46e5;
  --brand-700: #4338ca;
  --slate-50: #f8fafc;
  --slate-100: #f1f5f9;
  --slate-200: #e2e8f0;
  --slate-300: #cbd5e1;
  --slate-400: #94a3b8;
  --slate-500: #64748b;
  --slate-600: #475569;
  --slate-700: #334155;
  --slate-800: #1e293b;
  --slate-900: #0f172a;
  
  --sky-50: #f0f9ff;
  --sky-100: #e0f2fe;
  --sky-700: #0369a1;
  
  --purple-50: #faf5ff;
  --purple-100: #f3e8ff;
  --purple-700: #6b21a8;
  
  --emerald-50: #ecfdf5;
  --emerald-100: #d1fae5;
  --emerald-700: #047857;
  
  --rose-50: #fff1f2;
  --rose-100: #ffe4e6;
  --rose-700: #be123c;
}

.dashboard-container {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  width: 100%;
  max-width: 80rem;
  margin: 0 auto;
  padding: 0.5rem 0;
}

.page-header-rec {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
}

.page-title-rec {
  font-size: 1.875rem;
  font-weight: 800;
  letter-spacing: -0.025em;
  color: var(--slate-900);
  margin: 0;
}

.page-subtitle-rec {
  font-size: 0.875rem;
  color: var(--slate-500);
  margin-top: 0.25rem;
  margin-bottom: 0;
}

.header-actions-rec {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.btn-rec {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1.25rem;
  border-radius: 0.75rem;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: all 0.15s ease;
  border: none;
}

.btn-rec-primary {
  background-color: var(--brand-600);
  color: #ffffff;
  box-shadow: 0 1px 2px rgba(79, 70, 229, 0.25);
}
.btn-rec-primary:hover {
  background-color: var(--brand-700);
}

.btn-rec-secondary {
  background-color: #ffffff;
  color: var(--slate-700);
  border: 1px solid var(--slate-200);
}
.btn-rec-secondary:hover {
  background-color: var(--slate-50);
}

.metrics-grid-rec {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 1rem;
}

.metric-card-rec {
  background-color: #ffffff;
  border-radius: 1rem;
  padding: 1.25rem 1rem;
  border: 1px solid var(--slate-200);
  text-align: center;
  box-shadow: 0 1px 2px rgba(0,0,0,0.03);
}

.metric-value-rec {
  font-size: 1.75rem;
  font-weight: 800;
  color: var(--slate-900);
  line-height: 1;
}

.metric-label-rec {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--slate-500);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-top: 0.5rem;
}

.card-rec {
  background-color: #ffffff;
  border-radius: 1rem;
  border: 1px solid var(--slate-200);
  box-shadow: 0 1px 2px rgba(0,0,0,0.04);
  overflow: hidden;
}

.table-header-nav-rec {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid var(--slate-200);
  flex-wrap: wrap;
  gap: 1rem;
}

.tab-menu-rec {
  display: flex;
  gap: 0.5rem;
}

.tab-item-rec {
  padding: 0.5rem 1rem;
  border-radius: 0.5rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--slate-600);
  background: transparent;
  border: none;
  cursor: pointer;
  text-decoration: none;
  font-family: inherit;
  transition: all 0.15s;
}
.tab-item-rec:hover {
  color: var(--slate-900);
  background: var(--slate-100);
}
.tab-item-rec.active {
  background: var(--slate-900) !important;
  color: #ffffff !important;
}

.filter-pills-rec {
  display: flex;
  gap: 0.375rem;
  flex-wrap: wrap;
}

.pill-rec {
  display: inline-flex;
  align-items: center;
  padding: 0.25rem 0.75rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--slate-600);
  background: var(--slate-100);
  border: 1px solid var(--slate-200);
  text-decoration: none;
  transition: all 0.15s;
}
.pill-rec:hover { background: var(--slate-200); }
.pill-rec.pill-dark { background: var(--slate-900); color: #ffffff; border-color: var(--slate-900); }
.pill-rec.pill-sky { background: var(--sky-100); color: var(--sky-700); border-color: var(--sky-100); }
.pill-rec.pill-purple { background: var(--purple-100); color: var(--purple-700); border-color: var(--purple-100); }
.pill-rec.pill-emerald { background: var(--emerald-100); color: var(--emerald-700); border-color: var(--emerald-100); }
.pill-rec.pill-rose { background: var(--rose-100); color: var(--rose-700); border-color: var(--rose-100); }

.table-toolbar-rec {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.875rem 1.5rem;
  background: var(--slate-50);
  border-bottom: 1px solid var(--slate-200);
  flex-wrap: wrap;
  gap: 1rem;
}

.search-box-rec {
  position: relative;
  flex: 1;
  min-width: 240px;
}
.search-box-rec input {
  width: 100%;
  padding: 0.5rem 0.875rem 0.5rem 2.25rem;
  border-radius: 0.625rem;
  border: 1px solid var(--slate-200);
  font-size: 0.875rem;
  outline: none;
}
.search-box-rec input:focus {
  border-color: var(--brand-600);
}
.search-icon-rec {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  width: 1rem;
  height: 1rem;
  color: var(--slate-400);
}

.filter-controls-rec {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.select-control-rec {
  padding: 0.5rem 0.75rem;
  border-radius: 0.625rem;
  border: 1px solid var(--slate-200);
  font-size: 0.875rem;
  background: #ffffff;
  color: var(--slate-700);
  outline: none;
}

.data-table-rec {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.875rem;
  text-align: left;
}
.data-table-rec th {
  padding: 0.75rem 1.25rem;
  background: var(--slate-50);
  color: var(--slate-500);
  font-weight: 600;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  border-bottom: 1px solid var(--slate-200);
}
.data-table-rec td {
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--slate-100);
  vertical-align: middle;
}
.data-table-rec tr:hover {
  background-color: rgba(248, 250, 252, 0.7);
}

.candidate-profile-rec {
  display: flex;
  align-items: center;
  gap: 0.875rem;
}
.avatar-circle-rec {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.875rem;
  color: var(--slate-800);
  background: var(--slate-200);
}
.candidate-name-rec {
  font-weight: 600;
  color: var(--slate-900);
}
.candidate-email-rec {
  font-size: 0.75rem;
  color: var(--slate-500);
}

.badge-rec {
  display: inline-flex;
  align-items: center;
  padding: 0.25rem 0.625rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 600;
  gap: 0.375rem;
  white-space: nowrap;
  text-transform: capitalize;
}
.badge-rec.badge-sky { background: var(--sky-100); color: var(--sky-700); }
.badge-rec.badge-purple { background: var(--purple-100); color: var(--purple-700); }
.badge-rec.badge-emerald { background: var(--emerald-100); color: var(--emerald-700); }
.badge-rec.badge-rose { background: var(--rose-100); color: var(--rose-700); }
.badge-rec.badge-dark { background: var(--slate-900); color: #ffffff; }

.badge-dot-rec {
  width: 0.375rem;
  height: 0.375rem;
  border-radius: 50%;
  background: currentColor;
  flex-shrink: 0;
}

.text-bold-rec { font-weight: 600; color: var(--slate-900); }
.text-match-rec { font-size: 0.75rem; font-weight: 700; color: var(--emerald-700); }
.tag-role-rec { font-weight: 600; color: var(--slate-800); }
.text-subtle-rec { font-size: 0.75rem; color: var(--slate-500); }
.next-step-tag-rec {
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--slate-600);
  background: var(--slate-100);
  padding: 0.25rem 0.625rem;
  border-radius: 0.375rem;
  white-space: nowrap;
  display: inline-block;
}

.bottom-grid-rec {
  display: flex;
  gap: 1.5rem;
}
.flex-1-rec { flex: 1; }
.flex-2-rec { flex: 2; }

.widget-card-rec {
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.widget-header-rec {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 1rem;
}
.widget-title-rec {
  font-size: 1rem;
  font-weight: 700;
  color: var(--slate-900);
  margin: 0;
}
.widget-subtitle-rec {
  font-size: 0.75rem;
  color: var(--slate-500);
  margin-top: 0.25rem;
  margin-bottom: 0;
}

.empty-state-rec {
  text-align: center;
  padding: 2rem 1rem;
  background: var(--slate-50);
  border-radius: 0.75rem;
  border: 1px dashed var(--slate-200);
}
.empty-icon-rec {
  width: 2.5rem;
  height: 2.5rem;
  margin: 0 auto 0.75rem auto;
  color: var(--slate-400);
}
.empty-title-rec {
  font-size: 0.875rem;
  font-weight: 700;
  color: var(--slate-800);
  margin-bottom: 0.25rem;
}
.empty-description-rec {
  font-size: 0.75rem;
  color: var(--slate-500);
  margin-bottom: 1rem;
}

.stat-highlight-rec {
  margin: 1rem 0 0.5rem 0;
}
.stat-number-rec {
  font-size: 2rem;
  font-weight: 800;
  color: var(--slate-900);
}
.stat-meta-rec {
  font-size: 0.75rem;
  color: var(--slate-500);
  margin-left: 0.5rem;
}

.progress-bar-bg-rec {
  width: 100%;
  height: 0.5rem;
  background: var(--slate-100);
  border-radius: 9999px;
  overflow: hidden;
  margin-top: 0.5rem;
}
.progress-bar-fill-rec {
  height: 100%;
  background: var(--emerald-500);
  border-radius: 9999px;
}

.widget-footer-rec {
  display: flex;
  justify-content: space-between;
  font-size: 0.75rem;
  color: var(--slate-500);
  margin-top: 1rem;
  padding-top: 0.75rem;
  border-top: 1px solid var(--slate-100);
}

@media (max-width: 1024px) {
  .metrics-grid-rec { grid-template-columns: repeat(3, 1fr); }
  .bottom-grid-rec { flex-direction: column; }
}
@media (max-width: 640px) {
  .metrics-grid-rec { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="dashboard-container" style="width: 100%;">
    <!-- Page Header -->
    <div class="page-header-rec">
            <div>
                <h1 class="page-title-rec">Hiring Pipeline</h1>
                <p class="page-subtitle-rec">Move candidates through your stages and schedule interviews in structured view.</p>
            </div>
            <div class="header-actions-rec">
                <a href="export.php" class="btn-rec btn-rec-secondary">
                    <svg style="width: 1rem; height: 1rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                    Export CSV
                </a>
                <a href="recruiter-dashboard.php?new=1" class="btn-rec btn-rec-primary">
                    <svg style="width: 1rem; height: 1rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 4v16m8-8H4" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                    Post a Job
                </a>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <section class="metrics-grid-rec">
            <div class="metric-card-rec">
                <div class="metric-value-rec"><?= (int) ($jobStats['total_jobs'] ?? 0) ?></div>
                <div class="metric-label-rec">Total Jobs</div>
            </div>
            <div class="metric-card-rec">
                <div class="metric-value-rec"><?= (int) ($jobStats['active_jobs'] ?? 0) ?></div>
                <div class="metric-label-rec">Active</div>
            </div>
            <div class="metric-card-rec">
                <div class="metric-value-rec"><?= (int) ($jobStats['closed_jobs'] ?? 0) ?></div>
                <div class="metric-label-rec">Closed</div>
            </div>
            <div class="metric-card-rec">
                <div class="metric-value-rec"><?= $totalApplicants ?></div>
                <div class="metric-label-rec">Applicants</div>
            </div>
            <div class="metric-card-rec">
                <div class="metric-value-rec"><?= (int) ($appStats['pending_reviews'] ?? 0) ?></div>
                <div class="metric-label-rec">To Review</div>
            </div>
            <div class="metric-card-rec">
                <div class="metric-value-rec"><?= $hiredCandidates ?></div>
                <div class="metric-label-rec">Hired</div>
            </div>
        </section>

        <!-- Main Card Section (Table / Jobs) -->
        <div class="card-rec">
            <div class="table-header-nav-rec">
                <nav class="tab-menu-rec">
                    <button type="button" data-tab="pipeline" onclick="switchRecruiterTabSPA(event, 'pipeline')" class="tab-item-rec <?= $tab === 'pipeline' ? 'active' : '' ?>">Pipeline Table</button>
                    <button type="button" data-tab="jobs" onclick="switchRecruiterTabSPA(event, 'jobs')" class="tab-item-rec <?= $tab === 'jobs' ? 'active' : '' ?>">My Jobs</button>
                    <button type="button" data-tab="applicants" onclick="switchRecruiterTabSPA(event, 'applicants')" class="tab-item-rec <?= $tab === 'applicants' ? 'active' : '' ?>">All Applicants</button>
                </nav>
                <div class="filter-pills-rec">
                    <button type="button" class="pill-rec <?= $filterStatus === '' ? 'pill-dark active' : '' ?>" onclick="filterStatusSPA(event, 'all')">All (<?= count($candidateItems) ?>)</button>
                    <button type="button" class="pill-rec <?= $filterStatus === 'applied' ? 'pill-dark active' : '' ?>" onclick="filterStatusSPA(event, 'applied')">Applied</button>
                    <button type="button" class="pill-rec pill-sky <?= $filterStatus === 'under_review' ? 'active' : '' ?>" onclick="filterStatusSPA(event, 'under_review')">Under Review</button>
                    <button type="button" class="pill-rec pill-purple <?= $filterStatus === 'shortlisted' ? 'active' : '' ?>" onclick="filterStatusSPA(event, 'shortlisted')">Shortlisted</button>
                    <button type="button" class="pill-rec pill-emerald <?= $filterStatus === 'hired' ? 'active' : '' ?>" onclick="filterStatusSPA(event, 'hired')">Hired</button>
                    <button type="button" class="pill-rec pill-rose <?= $filterStatus === 'rejected' ? 'active' : '' ?>" onclick="filterStatusSPA(event, 'rejected')">Rejected</button>
                </div>
            </div>

            <!-- ---------- Jobs Table View Container ---------- -->
            <div id="rec-tab-view-jobs" style="<?= $tab === 'jobs' ? '' : 'display: none;' ?>">
                <div style="padding: 1rem 1.5rem;">
                    <?php if (empty($jobs)): ?>
                        <p style="text-align:center; padding: 2rem 0; color: var(--slate-500);">No jobs posted yet. Click <strong>Post a Job</strong> to get started.</p>
                    <?php else: ?>
                        <table class="data-table-rec">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Type & Mode</th>
                                    <th>Status</th>
                                    <th>Applicants</th>
                                    <th>Deadline</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobs as $job): ?>
                                    <tr>
                                        <td>
                                            <div class="candidate-name-rec"><?= e($job['title']) ?></div>
                                            <div class="candidate-email-rec"><?= e($job['location'] ?: 'Remote') ?> • <?= e($job['salary'] ?: 'Competitive') ?></div>
                                        </td>
                                        <td>
                                            <span class="tag-role-rec"><?= e(ucfirst($job['job_type'])) ?></span>
                                            <div class="text-subtle-rec"><?= e(ucfirst($job['work_mode'])) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge-rec <?= $job['status'] === 'open' ? 'badge-emerald' : 'badge-rose' ?>">
                                                <span class="badge-dot-rec"></span>
                                                <?= e(ucfirst($job['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-bold-rec"><?= (int) $job['applicant_count'] ?></td>
                                        <td class="text-subtle-rec"><?= $job['deadline'] ? format_date($job['deadline']) : 'Open' ?></td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem;">
                                                <a class="btn-rec btn-rec-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;" href="recruiter-dashboard.php?edit=<?= (int) $job['id'] ?>">Edit</a>

                                                <?php if ($job['status'] === 'open'): ?>
                                                    <form method="post" action="recruiter-dashboard.php">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                                        <input type="hidden" name="status" value="closed">
                                                        <button type="submit" class="btn-rec btn-rec-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;">Close</button>
                                                    </form>
                                                <?php else: ?>
                                                    <a class="btn-rec btn-rec-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem;" href="recruiter-dashboard.php?tab=jobs&reopen=<?= (int) $job['id'] ?>">Reopen</a>
                                                <?php endif; ?>

                                                <form method="post" action="recruiter-dashboard.php" onsubmit="return confirm('Delete this job permanently?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_job">
                                                    <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                                    <button type="submit" class="btn-rec btn-rec-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; color: var(--rose-700);">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ---------- Candidates / Pipeline Table View Container ---------- -->
            <div id="rec-tab-view-candidates" style="<?= $tab !== 'jobs' ? '' : 'display: none;' ?>">
                <div class="table-toolbar-rec">
                    <div class="search-box-rec">
                        <svg class="search-icon-rec" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                        <input type="text" id="candidateSearch" placeholder="Search candidate by name, role, skills..." value="<?= e($filterQuery) ?>"/>
                    </div>
                    <form method="get" action="recruiter-dashboard.php" class="filter-controls-rec">
                        <input type="hidden" name="tab" value="<?= e($tab) ?>">
                        <select name="job_id" class="select-control-rec" onchange="this.form.submit()">
                            <option value="">All Job Roles</option>
                            <?php foreach ($jobs as $job): ?>
                                <option value="<?= (int) $job['id'] ?>" <?= $filterJobId === (int) $job['id'] ? 'selected' : '' ?>>
                                    <?= e($job['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select id="statusSelectFilter" name="status" class="select-control-rec" onchange="filterStatusSPA(event, this.value)">
                            <option value="all">Filter Stage: All</option>
                            <?php foreach (APPLICATION_STATUSES as $val => $lbl): ?>
                                <option value="<?= e($val) ?>" <?= $filterStatus === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>

                <form method="post" action="recruiter-dashboard.php" id="bulkActionForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bulk_action">
                    <input type="hidden" name="bulk_status" id="bulkStatusInput" value="">
                    <input type="hidden" name="bulk_interview_at" id="bulkInterviewInput" value="">
                    <input type="hidden" name="bulk_meeting_link" id="bulkMeetingLinkInput" value="">
                    <input type="hidden" name="bulk_notes" id="bulkNotesInput" value="">

                    <div style="overflow-x: auto;">
                        <?php if (empty($candidateItems)): ?>
                            <p style="text-align:center; padding: 2.5rem 0; color: var(--slate-500);">No candidates found matching criteria.</p>
                        <?php else: ?>
                            <table class="data-table-rec">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;"><input type="checkbox" id="selectAllCandidates"/></th>
                                        <th>Candidate</th>
                                        <th>Role Applied</th>
                                        <th style="white-space: nowrap;">Current Stage</th>
                                        <th>Match &amp; Education</th>
                                        <th style="white-space: nowrap;">Next Step</th>
                                        <th style="text-align: right;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($candidateItems as $candidate): ?>
                                    <tr data-status="<?= e($candidate['raw_status']) ?>">
                                        <td><input type="checkbox" name="application_ids[]" value="<?= (int) $candidate['id'] ?>" class="candidate-checkbox"/></td>
                                        <td>
                                            <div class="candidate-profile-rec">
                                                <div class="avatar-circle-rec">
                                                    <?= e($candidate['initials']) ?>
                                                </div>
                                                <div>
                                                    <div class="candidate-name-rec"><?= e($candidate['name']) ?></div>
                                                    <div class="candidate-email-rec"><?= e($candidate['email']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="tag-role-rec"><?= e($candidate['role']) ?></span>
                                            <div class="text-subtle-rec"><?= e($candidate['applied']) ?></div>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <span class="badge-rec <?= e($candidate['stage_badge']) ?>">
                                                <span class="badge-dot-rec"></span>
                                                <?= e($candidate['stage']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="text-bold-rec"><?= e($candidate['exp']) ?></div>
                                            <div class="text-match-rec"><?= e($candidate['match']) ?></div>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <span class="next-step-tag-rec"><?= e($candidate['next_step']) ?></span>
                                        </td>
                                        <td style="text-align: right;">
                                            <a class="btn-rec btn-rec-secondary" style="padding: 0.35rem 0.875rem; font-size: 0.75rem;" 
                                               href="recruiter-dashboard.php?tab=applicants&review=<?= (int) $candidate['id'] ?>">
                                                <?= e($candidate['action']) ?>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!-- Floating Bulk Action Toolbar -->
                    <div id="bulkActionBar" style="display: none; align-items: center; justify-content: space-between; padding: 0.875rem 1.5rem; background: var(--slate-900); color: #ffffff; border-radius: 0.75rem; margin: 1rem; box-shadow: 0 4px 14px rgba(15,23,42,0.25);">
                        <div style="font-weight: 600; font-size: 0.875rem;">
                            <span id="selectedCount" style="color: var(--brand-400); font-weight: 800; font-size: 1rem;">0</span> candidates selected
                        </div>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <button type="button" onclick="submitBulkAction('shortlisted')" class="btn-rec" style="background: var(--purple-700); color: #fff; font-size: 0.75rem; padding: 0.4rem 0.875rem;">
                                🟣 Bulk Shortlist
                            </button>
                            <button type="button" onclick="submitBulkAction('under_review')" class="btn-rec" style="background: var(--sky-700); color: #fff; font-size: 0.75rem; padding: 0.4rem 0.875rem;">
                                🔵 Under Review
                            </button>
                            <button type="button" onclick="submitBulkAction('hired')" class="btn-rec" style="background: var(--emerald-700); color: #fff; font-size: 0.75rem; padding: 0.4rem 0.875rem;">
                                🟢 Bulk Hire
                            </button>
                            <button type="button" onclick="submitBulkAction('rejected')" class="btn-rec" style="background: var(--rose-700); color: #fff; font-size: 0.75rem; padding: 0.4rem 0.875rem;">
                                ❌ Bulk Reject
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Bottom Analytics Grid -->
        <section class="bottom-grid-rec">
            <!-- Upcoming Interviews Widget -->
            <div class="card-rec widget-card-rec flex-2-rec">
                <div class="widget-header-rec">
                    <div>
                        <h3 class="widget-title-rec">Upcoming Interviews</h3>
                        <p class="widget-subtitle-rec">Schedule interviews directly from candidate review panel</p>
                    </div>
                </div>
                
                <?php if (empty($interviews)): ?>
                    <div class="empty-state-rec">
                        <div class="empty-icon-rec">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                        </div>
                        <div class="empty-title-rec">No interviews scheduled for today</div>
                        <p class="empty-description-rec">Assign an interview slot in candidate review modal to track technical rounds.</p>
                        <a href="recruiter-dashboard.php?tab=applicants" class="btn-rec btn-rec-secondary" style="font-size: 0.75rem; display: inline-flex;">+ Review Applicants</a>
                    </div>
                <?php else: ?>
                    <div style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 260px; overflow-y: auto; padding-right: 0.375rem; scrollbar-width: thin;">
                        <?php foreach ($interviews as $inv):
                            $personName = (string) ($inv['full_name'] ?? $inv['person'] ?? 'Candidate');
                        ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: var(--slate-50); border-radius: 0.75rem; border: 1px solid var(--slate-200);">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <div class="avatar-circle-rec"><?= e(initials($personName)) ?></div>
                                    <div>
                                        <div class="candidate-name-rec"><?= e($personName) ?></div>
                                        <div class="text-subtle-rec"><?= e($inv['job_title']) ?></div>
                                    </div>
                                </div>
                                <span class="badge-rec badge-sky">📅 <?= e(format_datetime($inv['interview_at'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Hire Rate Widget -->
            <div class="card-rec widget-card-rec flex-1-rec">
                <div>
                    <div class="widget-header-rec">
                        <h3 class="widget-title-rec">Hire Rate</h3>
                        <span class="badge-rec badge-emerald">+12% vs last mo</span>
                    </div>
                    <p class="widget-subtitle-rec">Conversion from screening to offer acceptance.</p>
                    <div class="stat-highlight-rec">
                        <span class="stat-number-rec"><?= $hireRate ?>%</span>
                        <span class="stat-meta-rec"><?= $hiredCandidates ?> of <?= $totalApplicants ?> candidates hired</span>
                    </div>
                    <div class="progress-bar-bg-rec">
                        <div class="progress-bar-fill-rec" style="width: <?= min(100, $hireRate) ?>%;"></div>
                    </div>
                </div>
                <div class="widget-footer-rec">
                    <span>Avg time to hire: <strong>14 days</strong></span>
                    <span>Target: <strong>21 days</strong></span>
                </div>
            </div>
        </section>
    </div>

<!-- ---------- Post / Edit Job Dialog ---------- -->
<?php if ($showJobDialog): ?>
  <div class="modal-overlay show">
    <div class="modal" style="max-width: 600px;">
      <div class="modal-header">
        <h3 class="mb-0"><?= $editJob ? 'Edit Job Posting' : 'Create New Job Posting' ?></h3>
        <a class="modal-close" href="recruiter-dashboard.php?tab=jobs">&times;</a>
      </div>

      <form method="post" action="recruiter-dashboard.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_job">
        <?php if ($editJob): ?>
          <input type="hidden" name="job_id" value="<?= (int) $editJob['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
          <label for="title">Job Title</label>
          <input type="text" id="title" name="title" required maxlength="150"
                 value="<?= e(old($formOld, 'title', $editJob['title'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label for="description">Job Description</label>
          <textarea id="description" name="description" rows="3" required><?= e(old($formOld, 'description', $editJob['description'] ?? '')) ?></textarea>
        </div>
        <div class="form-group">
          <label for="requirements">Requirements</label>
          <textarea id="requirements" name="requirements" rows="2"><?= e(old($formOld, 'requirements', $editJob['requirements'] ?? '')) ?></textarea>
        </div>
        <div class="form-group">
          <label for="skills_required">Skills Required (Comma separated)</label>
          <input type="text" id="skills_required" name="skills_required" placeholder="e.g. PHP, MySQL, React"
                 value="<?= e(old($formOld, 'skills_required', $editJob['skills_required'] ?? '')) ?>">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="job_type">Job Type</label>
            <?php $selectedType = old($formOld, 'job_type', $editJob['job_type'] ?? ''); ?>
            <select id="job_type" name="job_type" required>
              <?php foreach (JOB_TYPES as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $selectedType === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="work_mode">Work Mode</label>
            <?php $selectedMode = old($formOld, 'work_mode', $editJob['work_mode'] ?? ''); ?>
            <select id="work_mode" name="work_mode" required>
              <?php foreach (WORK_MODES as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $selectedMode === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="location">Location</label>
            <input type="text" id="location" name="location"
                   value="<?= e(old($formOld, 'location', $editJob['location'] ?? '')) ?>">
          </div>
          <div class="form-group">
            <label for="salary">Salary</label>
            <input type="text" id="salary" name="salary" placeholder="e.g. 6 - 9 LPA"
                   value="<?= e(old($formOld, 'salary', $editJob['salary'] ?? '')) ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="vacancy_count">Vacancy Count</label>
            <input type="number" id="vacancy_count" name="vacancy_count" min="1" required
                   value="<?= e(old($formOld, 'vacancy_count', (string) ($editJob['vacancy_count'] ?? 1))) ?>">
          </div>
          <div class="form-group">
            <label for="deadline">Application Deadline</label>
            <input type="date" id="deadline" name="deadline"
                   value="<?= e(old($formOld, 'deadline', $editJob['deadline'] ?? '')) ?>">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Save Job</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<!-- ---------- Applicant Review Dialog ---------- -->
<?php if ($reviewApplicant): ?>
  <div class="modal-overlay show">
    <div class="modal" style="max-width: 650px;">
      <div class="modal-header">
        <h3 class="mb-0">Applicant Details</h3>
        <a class="modal-close" href="<?= e($applicantsUrl) ?>">&times;</a>
      </div>

      <p style="margin-bottom: 0.5rem;">
        <strong><?= e($reviewApplicant['full_name']) ?></strong> ·
        <?= e($reviewApplicant['email']) ?> <?= $reviewApplicant['phone'] ? ' · ' . e($reviewApplicant['phone']) : '' ?>
      </p>
      <p style="margin-bottom: 0.5rem;"><strong>Applied for:</strong> <?= e($reviewApplicant['job_title']) ?></p>
      <?php if ($reviewApplicant['education']): ?>
        <p style="margin-bottom: 0.5rem;"><strong>Education:</strong> <?= e($reviewApplicant['education']) ?></p>
      <?php endif; ?>
      <?php if ($reviewApplicant['skills']): ?>
        <p style="margin-bottom: 0.5rem;"><strong>Skills:</strong> <?= e($reviewApplicant['skills']) ?></p>
      <?php endif; ?>

      <?php if ($reviewApplicant['resume_path']): ?>
        <div style="margin-bottom: 1.25rem;">
          <div style="display: flex; gap: 8px; margin-bottom: 0.75rem; flex-wrap: wrap;">
            <a class="btn btn-primary btn-sm" target="_blank"
               href="download-resume.php?application_id=<?= (int) $reviewApplicant['application_id'] ?>&mode=preview">
              Open Fullscreen Preview
            </a>
            <a class="btn btn-secondary btn-sm"
               href="download-resume.php?application_id=<?= (int) $reviewApplicant['application_id'] ?>">
              Download Resume
            </a>
          </div>
          <div style="border: 1px solid #cbd5e1; border-radius: 0.75rem; overflow: hidden; background: #f8fafc;">
            <iframe src="download-resume.php?application_id=<?= (int) $reviewApplicant['application_id'] ?>&mode=preview"
                    style="width: 100%; height: 420px; border: none;" title="Resume Preview"></iframe>
          </div>
        </div>
      <?php else: ?>
        <p class="form-hint" style="margin-bottom: 1rem;">No resume uploaded by candidate.</p>
      <?php endif; ?>

      <form method="post" action="recruiter-dashboard.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_application">
        <input type="hidden" name="application_id" value="<?= (int) $reviewApplicant['application_id'] ?>">

        <div class="form-row">
          <div class="form-group">
            <label for="review_status">Application Status</label>
            <select id="review_status" name="status">
              <?php foreach (APPLICATION_STATUSES as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $reviewApplicant['status'] === $value ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="interview_at">Interview Date &amp; Time</label>
            <input type="datetime-local" id="interview_at" name="interview_at"
                   value="<?= e(datetime_local($reviewApplicant['interview_at'])) ?>">
            <div class="form-hint">Leave blank if nothing is scheduled.</div>
          </div>
        </div>

        <div class="form-group" style="margin-top: 0.5rem;">
          <label for="meeting_link">Meeting / Interview Link (Google Meet, Zoom, Teams)</label>
          <input type="url" id="meeting_link" name="meeting_link" placeholder="e.g. https://meet.google.com/abc-defg-hij or Zoom link"
                 value="<?= e($reviewApplicant['meeting_link'] ?? '') ?>">
          <div class="form-hint">Candidate will see a "Join Meeting" button directly on their dashboard and email.</div>
        </div>

        <div class="form-group">
          <label for="review_notes">Internal Notes &amp; Candidate Instructions</label>
          <textarea id="review_notes" name="notes" rows="2"
                    placeholder="Notes visible to hiring team and candidate"><?= e($reviewApplicant['notes'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<!-- ---------- Reopen Job Dialog ---------- -->
<?php if ($reopenJob): ?>
  <div class="modal-overlay show">
    <div class="modal" style="max-width: 480px;">
      <div class="modal-header">
        <h3 class="mb-0">Reopen Job</h3>
        <a class="modal-close" href="recruiter-dashboard.php?tab=jobs">&times;</a>
      </div>

      <p style="margin-bottom: 1rem; color: #475569;">
        You are reopening <strong><?= e($reopenJob['title']) ?></strong>.
        Please select an extended deadline date for accepting new applications.
      </p>

      <form method="post" action="recruiter-dashboard.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="job_id" value="<?= (int) $reopenJob['id'] ?>">
        <input type="hidden" name="status" value="open">

        <div class="form-group">
          <label for="reopen_deadline">New Application Deadline</label>
          <input type="date" id="reopen_deadline" name="deadline" required min="<?= date('Y-m-d') ?>"
                 value="<?= date('Y-m-d', strtotime('+14 days')) ?>">
          <div class="form-hint">Set a future date for candidates to submit applications.</div>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 1.5rem;">
          <a href="recruiter-dashboard.php?tab=jobs" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary">Reopen Job Now</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<!-- ---------- Bulk Action Confirmation Dialog ---------- -->
<div id="bulkConfirmModal" class="modal-overlay" style="display: none;">
  <div class="modal" style="max-width: 520px;">
    <div class="modal-header">
      <h3 class="mb-0">Confirm Bulk Action</h3>
      <button type="button" class="modal-close" onclick="closeBulkModal()">&times;</button>
    </div>

    <div style="padding: 1rem 0;">
      <p id="bulkModalMessage" style="font-size: 0.9375rem; color: var(--slate-700); margin-bottom: 1rem;"></p>

      <div class="form-group" style="margin-bottom: 1rem;" id="bulkInterviewGroup">
        <label for="modal_bulk_interview_at" style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--slate-700);">Interview Date &amp; Time (Optional)</label>
        <input type="datetime-local" id="modal_bulk_interview_at" class="input-control-prof">
        <div class="form-hint">Assign an interview slot for all selected candidates.</div>

        <label for="modal_bulk_meeting_link" style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--slate-700); margin-top: 0.75rem;">Meeting / Interview Link (Optional)</label>
        <input type="url" id="modal_bulk_meeting_link" class="input-control-prof" placeholder="e.g. https://meet.google.com/abc-defg-hij">
      </div>

      <div class="form-group" style="margin-bottom: 0.5rem;">
        <label for="modal_bulk_notes" style="font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--slate-700);">Notes / Message Description (Optional)</label>
        <textarea id="modal_bulk_notes" rows="3" class="input-control-prof" placeholder="Add custom notes or description to send to candidate records..."></textarea>
      </div>
    </div>

    <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 1rem;">
      <button type="button" class="btn btn-secondary" onclick="closeBulkModal()">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="confirmAndSubmitBulkAction()">OK, Proceed</button>
    </div>
  </div>
</div>

<script>
let pendingBulkStatus = '';

function updateBulkBar() {
    const checked = document.querySelectorAll('.candidate-checkbox:checked');
    const bulkBar = document.getElementById('bulkActionBar');
    const selectedCount = document.getElementById('selectedCount');
    if (bulkBar && selectedCount) {
        selectedCount.textContent = checked.length;
        bulkBar.style.display = checked.length > 0 ? 'flex' : 'none';
    }
}

function submitBulkAction(status) {
    const checked = document.querySelectorAll('.candidate-checkbox:checked');
    if (checked.length === 0) {
        alert('Please select at least one candidate.');
        return;
    }
    
    pendingBulkStatus = status;
    const label = status.replace('_', ' ').toUpperCase();
    const count = checked.length;
    
    const msgEl = document.getElementById('bulkModalMessage');
    if (msgEl) {
        msgEl.innerHTML = `Are you sure you want to move <strong>${count} candidate(s)</strong> to <strong>${label}</strong> stage?`;
    }
    
    // Reset inputs for clean modal prompt
    const modalInterview = document.getElementById('modal_bulk_interview_at');
    const modalNotes = document.getElementById('modal_bulk_notes');
    if (modalInterview) modalInterview.value = '';
    if (modalNotes) modalNotes.value = '';

    // Hide interview group if rejecting or withdrawing
    const intGroup = document.getElementById('bulkInterviewGroup');
    if (intGroup) {
        intGroup.style.display = (status === 'rejected' || status === 'withdrawn') ? 'none' : 'block';
    }

    const modal = document.getElementById('bulkConfirmModal');
    if (modal) {
        modal.style.display = 'flex';
        modal.classList.add('show');
    }
}

function closeBulkModal() {
    const modal = document.getElementById('bulkConfirmModal');
    if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
    }
}

function confirmAndSubmitBulkAction() {
    if (pendingBulkStatus) {
        document.getElementById('bulkStatusInput').value = pendingBulkStatus;

        const modalInterview = document.getElementById('modal_bulk_interview_at');
        const modalMeeting = document.getElementById('modal_bulk_meeting_link');
        const modalNotes = document.getElementById('modal_bulk_notes');

        if (modalInterview) {
            document.getElementById('bulkInterviewInput').value = modalInterview.value;
        }
        if (modalMeeting) {
            document.getElementById('bulkMeetingLinkInput').value = modalMeeting.value;
        }
        if (modalNotes) {
            document.getElementById('bulkNotesInput').value = modalNotes.value;
        }

        document.getElementById('bulkActionForm').submit();
    }
}

function filterStatusSPA(event, targetStatus) {
    if (event) event.preventDefault();
    targetStatus = targetStatus || 'all';

    // Push URL state for bookmarking/history without reload
    const url = new URL(window.location);
    if (targetStatus && targetStatus !== 'all') {
        url.searchParams.set('status', targetStatus);
    } else {
        url.searchParams.delete('status');
    }
    window.history.pushState({}, '', url);

    // Update Pills UI active state
    const pills = document.querySelectorAll('.filter-pills-rec .pill-rec');
    pills.forEach(pill => pill.classList.remove('pill-dark', 'active'));

    if (event && event.currentTarget && event.currentTarget.classList.contains('pill-rec')) {
        event.currentTarget.classList.add('pill-dark', 'active');
    } else {
        pills.forEach(pill => {
            const text = pill.textContent.toLowerCase().trim();
            const cleanTarget = targetStatus.replace('_', ' ');
            if ((targetStatus === 'all' && text.startsWith('all')) || text.includes(cleanTarget)) {
                pill.classList.add('pill-dark', 'active');
            }
        });
    }

    // Sync Select Dropdown
    const selectElem = document.getElementById('statusSelectFilter');
    if (selectElem && selectElem.value !== targetStatus) {
        selectElem.value = targetStatus === 'all' ? 'all' : targetStatus;
    }

    // Filter candidate table rows
    const searchInput = document.getElementById('candidateSearch');
    const searchQuery = searchInput ? searchInput.value.toLowerCase().trim() : '';
    const tableRows = document.querySelectorAll('.data-table-rec tbody tr');

    tableRows.forEach(row => {
        const rowStatus = row.getAttribute('data-status');
        const text = row.textContent.toLowerCase();

        const matchesStatus = (targetStatus === 'all' || rowStatus === targetStatus);
        const matchesSearch = (!searchQuery || text.includes(searchQuery));

        row.style.display = (matchesStatus && matchesSearch) ? '' : 'none';
    });
}

function switchRecruiterTabSPA(event, tabName) {
    if (event) event.preventDefault();
    tabName = tabName || 'pipeline';

    const jobsView = document.getElementById('rec-tab-view-jobs');
    const candidatesView = document.getElementById('rec-tab-view-candidates');

    if (tabName === 'jobs') {
        if (jobsView) jobsView.style.display = 'block';
        if (candidatesView) candidatesView.style.display = 'none';
    } else {
        if (jobsView) jobsView.style.display = 'none';
        if (candidatesView) candidatesView.style.display = 'block';
    }

    // Update active tab button style
    document.querySelectorAll('.tab-menu-rec .tab-item-rec').forEach(btn => {
        const btnTab = btn.getAttribute('data-tab');
        btn.classList.toggle('active', btnTab === tabName);
    });

    // Update active sidebar link style
    document.querySelectorAll('.sidebar .nav-group a').forEach(link => {
        if (link.href && link.href.includes('recruiter-dashboard.php')) {
            if (tabName === 'jobs' && link.href.includes('tab=jobs')) {
                link.classList.add('active');
            } else if (tabName === 'pipeline' && link.href.includes('tab=pipeline')) {
                link.classList.add('active');
            } else if (tabName === 'applicants' && link.href.includes('tab=applicants')) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        }
    });

    // Push URL without page reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.pushState({}, '', url);
}

document.addEventListener('DOMContentLoaded', () => {
    const initialStatus = '<?= e($filterStatus) ?>';
    if (initialStatus) {
        filterStatusSPA(null, initialStatus);
    }

    // Intercept sidebar rail clicks for tab switching without page reload
    document.querySelectorAll('.sidebar a[href*="recruiter-dashboard.php"]').forEach(link => {
        link.addEventListener('click', function(e) {
            if (window.location.pathname.endsWith('recruiter-dashboard.php')) {
                const urlParams = new URLSearchParams(this.search);
                const tab = urlParams.get('tab') || 'pipeline';
                e.preventDefault();
                switchRecruiterTabSPA(e, tab);
            }
        });
    });

    const searchInput = document.getElementById('candidateSearch');
    const tableRows = document.querySelectorAll('.data-table-rec tbody tr');
    
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const selectElem = document.getElementById('statusSelectFilter');
            const targetStatus = selectElem ? selectElem.value : 'all';

            tableRows.forEach(row => {
                const rowStatus = row.getAttribute('data-status');
                const text = row.textContent.toLowerCase();
                const matchesStatus = (targetStatus === 'all' || rowStatus === targetStatus);
                const matchesSearch = (!query || text.includes(query));

                row.style.display = (matchesStatus && matchesSearch) ? '' : 'none';
            });
        });
    }

    const selectAll = document.getElementById('selectAllCandidates');
    const rowCheckboxes = document.querySelectorAll('.candidate-checkbox');
    if (selectAll) {
        selectAll.addEventListener('change', (e) => {
            rowCheckboxes.forEach(cb => cb.checked = e.target.checked);
            updateBulkBar();
        });
    }
    
    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkBar);
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>