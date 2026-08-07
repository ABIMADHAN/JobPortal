<?php
/**
 * recruiter-dashboard.php
 * Recruiter workspace.
 *
 *   ?tab=pipeline (default)  applicant board grouped by hiring stage
 *   ?tab=jobs                job postings table + CRUD
 *   ?tab=applicants          filterable applicant table
 *   ?new=1 / ?edit=<job_id>  job dialog
 *   ?review=<application_id> applicant review dialog
 *
 * All state changes POST back here and end in a redirect, so refreshing a page
 * never re-submits a form.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_role('recruiter');

$pdo = get_db();
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

// ---------------------------------------------------------------
// POST handlers
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
            flash('Job updated.');
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

    // ---- Open / close a job ----
    if ($action === 'toggle_status') {
        $jobId = (int) ($_POST['job_id'] ?? 0);
        $newStatus = post('status') === 'open' ? 'open' : 'closed';

        $stmt = $pdo->prepare('UPDATE jobs SET status = :status WHERE id = :id AND company_id = :company_id');
        $stmt->execute([':status' => $newStatus, ':id' => $jobId, ':company_id' => $companyId]);

        if ($stmt->rowCount() === 0) {
            flash('Job not found, or you do not have permission to update it.', 'error');
        } else {
            flash($newStatus === 'open' ? 'Job reopened.' : 'Job closed.');
        }
        redirect('recruiter-dashboard.php?tab=jobs');
    }

    // ---- Delete a job (applications cascade via the foreign key) ----
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

    // ---- Update an applicant's status, interview slot and notes ----
    if ($action === 'update_application') {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $status = post('status');
        $notes = post('notes');
        $interviewAt = post('interview_at');

        if (!isset(APPLICATION_STATUSES[$status])) {
            flash('Invalid status value.', 'error');
            redirect('recruiter-dashboard.php?tab=applicants');
        }

        // <input type="datetime-local"> submits YYYY-MM-DDTHH:MM
        $interviewValue = null;
        if ($interviewAt !== '') {
            $ts = strtotime($interviewAt);
            if ($ts === false) {
                flash('Interview date is not a valid date and time.', 'error');
                redirect('recruiter-dashboard.php?tab=applicants&review=' . $applicationId);
            }
            $interviewValue = date('Y-m-d H:i:s', $ts);
        }

        // Read the current row first so we only email the student about fields
        // that actually changed — re-saving the dialog unchanged sends nothing.
        $stmt = $pdo->prepare(
            'SELECT a.status, a.notes, a.interview_at
             FROM applications a
             INNER JOIN jobs j ON a.job_id = j.id
             WHERE a.id = :id AND j.company_id = :company_id LIMIT 1'
        );
        $stmt->execute([':id' => $applicationId, ':company_id' => $companyId]);
        $before = $stmt->fetch();

        $stmt = $pdo->prepare(
            'UPDATE applications a
             INNER JOIN jobs j ON a.job_id = j.id
             SET a.status = :status, a.notes = :notes, a.interview_at = :interview_at
             WHERE a.id = :id AND j.company_id = :company_id'
        );
        $stmt->execute([
            ':status' => $status,
            ':notes' => $notes,
            ':interview_at' => $interviewValue,
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
            // Loaded on demand so ordinary dashboard views never pull in PHPMailer.
            require_once __DIR__ . '/notifications.php';
            notify_application_updated($pdo, $applicationId, $changed);
            flash('Applicant updated. The student has been emailed.');
        } else {
            flash('Applicant updated.');
        }

        redirect('recruiter-dashboard.php?tab=applicants');
    }

    redirect('recruiter-dashboard.php');
}

// ---------------------------------------------------------------
// Stats
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

$totalApplicants = (int) ($appStats['total_applicants'] ?? 0);
$hiredCandidates = (int) ($appStats['hired_candidates'] ?? 0);
$hireRate = $totalApplicants > 0 ? (int) round($hiredCandidates / $totalApplicants * 100) : 0;

$donutCircumference = 251.2;
$donutOffset = $donutCircumference - ($donutCircumference * $hireRate / 100);

// ---------------------------------------------------------------
// Jobs
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT j.*, (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applicant_count
     FROM jobs j
     WHERE j.company_id = :company_id
     ORDER BY j.created_at DESC'
);
$stmt->execute([':company_id' => $companyId]);
$jobs = $stmt->fetchAll();

// ---------------------------------------------------------------
// Applicants (with filters)
// ---------------------------------------------------------------
$filterJobId = (int) query('job_id');
$filterStatus = query('status');

$where = ['j.company_id = :company_id'];
$params = [':company_id' => $companyId];

if ($filterJobId > 0) {
    $where[] = 'a.job_id = :job_id';
    $params[':job_id'] = $filterJobId;
}
if (isset(APPLICATION_STATUSES[$filterStatus])) {
    $where[] = 'a.status = :status';
    $params[':status'] = $filterStatus;
} else {
    $filterStatus = '';
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT a.id AS application_id, a.status, a.notes, a.applied_at, a.interview_at,
            j.title AS job_title,
            u.full_name, u.email, u.phone,
            sp.education, sp.skills, sp.bio, sp.resume_path, sp.resume_original_name
     FROM applications a
     INNER JOIN jobs j ON a.job_id = j.id
     INNER JOIN users u ON a.student_id = u.id
     LEFT JOIN student_profiles sp ON sp.user_id = u.id
     WHERE $whereSql
     ORDER BY a.applied_at DESC"
);
$stmt->execute($params);
$applicants = $stmt->fetchAll();

// Unfiltered set feeds the pipeline board, so filters only affect the table.
$stmt = $pdo->prepare(
    'SELECT a.id AS application_id, a.status, a.applied_at, a.interview_at,
            j.title AS job_title, u.full_name
     FROM applications a
     INNER JOIN jobs j ON a.job_id = j.id
     INNER JOIN users u ON a.student_id = u.id
     WHERE j.company_id = :company_id
     ORDER BY a.applied_at DESC'
);
$stmt->execute([':company_id' => $companyId]);
$pipeline = [];
foreach (array_keys(APPLICATION_STATUSES) as $key) {
    $pipeline[$key] = [];
}
foreach ($stmt->fetchAll() as $row) {
    if (isset($pipeline[$row['status']])) {
        $pipeline[$row['status']][] = $row;
    }
}

$interviews = upcoming_interviews($pdo);

// ---------------------------------------------------------------
// Tab / dialog state
// ---------------------------------------------------------------
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

$pageTitle = 'Recruiter Dashboard';
$topbarTitle = $company['company_name'] . ' · Hiring';
$activeNav = match ($tab) {
    'jobs' => 'myjobs',
    'applicants' => 'applicants',
    default => 'dashboard',
};
$layout = 'app';
require __DIR__ . '/header.php';
?>

<main class="canvas">

  <div class="canvas-header">
    <div>
      <h2 class="canvas-title">Hiring Pipeline</h2>
      <p class="canvas-sub">Move candidates through your stages and schedule interviews.</p>
    </div>
    <div class="canvas-actions">
      <a href="export.php" class="btn btn-secondary"><?= nav_icon('export') ?> Export CSV</a>
      <a href="recruiter-dashboard.php?new=1" class="btn btn-primary"><?= nav_icon('add') ?> Post a Job</a>
    </div>
  </div>

  <div class="stat-strip">
    <div class="stat-chip"><span class="stat-chip-value"><?= (int) ($jobStats['total_jobs'] ?? 0) ?></span><span class="stat-chip-label">Total Jobs</span></div>
    <div class="stat-chip"><span class="stat-chip-value"><?= (int) ($jobStats['active_jobs'] ?? 0) ?></span><span class="stat-chip-label">Active</span></div>
    <div class="stat-chip"><span class="stat-chip-value"><?= (int) ($jobStats['closed_jobs'] ?? 0) ?></span><span class="stat-chip-label">Closed</span></div>
    <div class="stat-chip"><span class="stat-chip-value"><?= $totalApplicants ?></span><span class="stat-chip-label">Applicants</span></div>
    <div class="stat-chip"><span class="stat-chip-value"><?= (int) ($appStats['pending_reviews'] ?? 0) ?></span><span class="stat-chip-label">To Review</span></div>
    <div class="stat-chip"><span class="stat-chip-value"><?= $hiredCandidates ?></span><span class="stat-chip-label">Hired</span></div>
  </div>

  <div class="tabs">
    <a class="tab-btn<?= $tab === 'pipeline' ? ' active' : '' ?>" href="recruiter-dashboard.php">Pipeline</a>
    <a class="tab-btn<?= $tab === 'jobs' ? ' active' : '' ?>" href="recruiter-dashboard.php?tab=jobs">My Jobs</a>
    <a class="tab-btn<?= $tab === 'applicants' ? ' active' : '' ?>" href="recruiter-dashboard.php?tab=applicants">Applicants</a>
  </div>

  <?php if ($tab === 'pipeline'): ?>
    <!-- ---------- Pipeline board ---------- -->
    <?php if (!$totalApplicants): ?>
      <div class="card">
        <div class="empty-state">
          No applicants yet.
          <?php if (!($jobStats['total_jobs'] ?? 0)): ?>
            <a href="recruiter-dashboard.php?new=1">Post your first job</a> to start receiving applications.
          <?php else: ?>
            Share your open roles to start receiving applications.
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="board">
        <?php foreach (APPLICATION_STATUSES as $key => $label): ?>
          <section class="board-col<?= $key === 'shortlisted' ? ' board-col-accent' : '' ?>">
            <div class="board-col-head">
              <h3><?= e($label) ?> <span class="board-count"><?= count($pipeline[$key]) ?></span></h3>
            </div>
            <div class="board-col-body">
              <?php if (!$pipeline[$key]): ?>
                <p class="board-empty">Nothing here yet.</p>
              <?php endif; ?>

              <?php foreach ($pipeline[$key] as $row): ?>
                <article class="board-card<?= $key === 'shortlisted' ? ' board-card-accent' : '' ?>">
                  <div class="board-card-company"><?= e($row['job_title']) ?></div>
                  <h4 class="board-card-title">
                    <a href="recruiter-dashboard.php?tab=applicants&review=<?= (int) $row['application_id'] ?>">
                      <?= e($row['full_name']) ?>
                    </a>
                  </h4>

                  <?php if ($row['interview_at'] && strtotime($row['interview_at']) >= time()): ?>
                    <div class="board-card-note">Interview <?= e(format_datetime($row['interview_at'])) ?></div>
                  <?php endif; ?>

                  <div class="board-card-foot">
                    <span class="badge badge-<?= e($row['status']) ?>"><?= e(status_label($row['status'])) ?></span>
                    <a class="btn btn-secondary btn-sm"
                       href="recruiter-dashboard.php?tab=applicants&review=<?= (int) $row['application_id'] ?>">Review</a>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($tab === 'jobs'): ?>
    <!-- ---------- Jobs table ---------- -->
    <div class="card">
      <?php if (!$jobs): ?>
        <div class="empty-state">You haven't posted any jobs yet.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr><th>Title</th><th>Type</th><th>Status</th><th>Applicants</th><th>Posted</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($jobs as $job): ?>
              <tr>
                <td data-label="Title">
                  <a href="jobs.php?id=<?= (int) $job['id'] ?>" target="_blank"><?= e($job['title']) ?></a>
                </td>
                <td data-label="Type"><?= e($job['job_type']) ?></td>
                <td data-label="Status">
                  <span class="badge badge-<?= e($job['status']) ?>"><?= e($job['status']) ?></span>
                </td>
                <td data-label="Applicants"><?= (int) $job['applicant_count'] ?></td>
                <td data-label="Posted"><?= e(format_date($job['created_at'])) ?></td>
                <td data-label="Actions">
                  <div class="table-actions">
                    <a class="btn btn-secondary btn-sm" href="recruiter-dashboard.php?edit=<?= (int) $job['id'] ?>">Edit</a>

                    <form method="post" action="recruiter-dashboard.php">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                      <input type="hidden" name="status" value="<?= $job['status'] === 'open' ? 'closed' : 'open' ?>">
                      <button type="submit" class="btn btn-secondary btn-sm">
                        <?= $job['status'] === 'open' ? 'Close' : 'Reopen' ?>
                      </button>
                    </form>

                    <form method="post" action="recruiter-dashboard.php"
                          onsubmit="return confirm('Delete this job permanently? This also removes its applications.');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete_job">
                      <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <!-- ---------- Applicants table ---------- -->
    <div class="card">
      <form class="filters-bar" method="get" action="recruiter-dashboard.php" style="margin-bottom:16px;">
        <input type="hidden" name="tab" value="applicants">
        <select name="job_id" onchange="this.form.submit()">
          <option value="">All Jobs</option>
          <?php foreach ($jobs as $job): ?>
            <option value="<?= (int) $job['id'] ?>"<?= $filterJobId === (int) $job['id'] ? ' selected' : '' ?>>
              <?= e($job['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <?php foreach (APPLICATION_STATUSES as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $filterStatus === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
      </form>

      <?php if (!$applicants): ?>
        <div class="empty-state">No applicants found.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr><th>Applicant</th><th>Job</th><th>Applied</th><th>Interview</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($applicants as $applicant): ?>
              <tr>
                <td data-label="Applicant"><?= e($applicant['full_name']) ?></td>
                <td data-label="Job"><?= e($applicant['job_title']) ?></td>
                <td data-label="Applied"><?= e(format_date($applicant['applied_at'])) ?></td>
                <td data-label="Interview">
                  <?= $applicant['interview_at'] ? e(format_datetime($applicant['interview_at'])) : '—' ?>
                </td>
                <td data-label="Status">
                  <span class="badge badge-<?= e($applicant['status']) ?>"><?= e(status_label($applicant['status'])) ?></span>
                </td>
                <td data-label="Actions">
                  <a class="btn btn-secondary btn-sm"
                     href="<?= e($applicantsUrl) ?>&review=<?= (int) $applicant['application_id'] ?>">Review</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Floating widgets -->
  <div class="widget-dock">
    <section class="widget widget-interviews">
      <h4>Upcoming Interviews</h4>
      <?php if (!$interviews): ?>
        <p class="widget-empty">Schedule one from an applicant's review panel.</p>
      <?php else: ?>
        <ul class="widget-list">
          <?php foreach ($interviews as $iv): ?>
            <li>
              <span class="widget-avatar"><?= e(initials((string) $iv['person'])) ?></span>
              <span class="widget-lines">
                <span class="widget-name"><?= e($iv['person']) ?></span>
                <span class="widget-meta"><?= e($iv['job_title']) ?></span>
              </span>
              <span class="widget-date"><?= e(date('M j', strtotime($iv['interview_at']))) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="widget widget-rate">
      <h4>Hire Rate</h4>
      <div class="donut-wrapper">
        <svg class="donut-svg" viewBox="0 0 100 100">
          <circle class="donut-bg" cx="50" cy="50" r="40"></circle>
          <circle class="donut-progress" cx="50" cy="50" r="40"
                  stroke-dasharray="<?= $donutCircumference ?>"
                  stroke-dashoffset="<?= $donutOffset ?>"></circle>
        </svg>
        <div class="donut-text"><?= $hireRate ?>%</div>
      </div>
      <p class="widget-empty"><?= $hiredCandidates ?> of <?= $totalApplicants ?> hired</p>
    </section>
  </div>

</main>

<!-- ---------- Job dialog (create / edit) ---------- -->
<?php if ($showJobDialog): ?>
  <div class="modal-overlay show">
    <div class="modal">
      <div class="modal-header">
        <h3 class="mb-0"><?= $editJob ? 'Edit Job' : 'Post a Job' ?></h3>
        <a class="modal-close" href="recruiter-dashboard.php?tab=jobs">&times;</a>
      </div>

      <?php if ($formError): ?>
        <div class="alert alert-error show"><?= e($formError) ?></div>
      <?php endif; ?>

      <form method="post" action="recruiter-dashboard.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_job">
        <?php if ($editJob): ?>
          <input type="hidden" name="job_id" value="<?= (int) $editJob['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
          <label for="title">Job title</label>
          <input type="text" id="title" name="title" required maxlength="150"
                 value="<?= e(old($formOld, 'title', $editJob['title'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label for="description">Description</label>
          <textarea id="description" name="description" required><?= e(old($formOld, 'description', $editJob['description'] ?? '')) ?></textarea>
        </div>
        <div class="form-group">
          <label for="requirements">Requirements</label>
          <textarea id="requirements" name="requirements"><?= e(old($formOld, 'requirements', $editJob['requirements'] ?? '')) ?></textarea>
        </div>
        <div class="form-group">
          <label for="skills_required">Skills required (comma separated)</label>
          <input type="text" id="skills_required" name="skills_required"
                 value="<?= e(old($formOld, 'skills_required', $editJob['skills_required'] ?? '')) ?>">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="job_type">Job type</label>
            <?php $selectedType = old($formOld, 'job_type', $editJob['job_type'] ?? ''); ?>
            <select id="job_type" name="job_type" required>
              <?php foreach (JOB_TYPES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $selectedType === $value ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="work_mode">Work mode</label>
            <?php $selectedMode = old($formOld, 'work_mode', $editJob['work_mode'] ?? ''); ?>
            <select id="work_mode" name="work_mode" required>
              <?php foreach (WORK_MODES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $selectedMode === $value ? ' selected' : '' ?>><?= e($label) ?></option>
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
            <label for="vacancy_count">Vacancy count</label>
            <input type="number" id="vacancy_count" name="vacancy_count" min="1" required
                   value="<?= e(old($formOld, 'vacancy_count', (string) ($editJob['vacancy_count'] ?? 1))) ?>">
          </div>
          <div class="form-group">
            <label for="deadline">Application deadline</label>
            <input type="date" id="deadline" name="deadline"
                   value="<?= e(old($formOld, 'deadline', $editJob['deadline'] ?? '')) ?>">
          </div>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Save Job</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<!-- ---------- Applicant review dialog ---------- -->
<?php if ($reviewApplicant): ?>
  <div class="modal-overlay show">
    <div class="modal">
      <div class="modal-header">
        <h3 class="mb-0">Applicant Details</h3>
        <a class="modal-close" href="<?= e($applicantsUrl) ?>">&times;</a>
      </div>

      <p>
        <strong><?= e($reviewApplicant['full_name']) ?></strong> ·
        <?= e($reviewApplicant['email']) ?><?= $reviewApplicant['phone'] ? ' · ' . e($reviewApplicant['phone']) : '' ?>
      </p>
      <p><strong>Applied for:</strong> <?= e($reviewApplicant['job_title']) ?></p>
      <?php if ($reviewApplicant['education']): ?>
        <p><strong>Education:</strong> <?= e($reviewApplicant['education']) ?></p>
      <?php endif; ?>
      <?php if ($reviewApplicant['skills']): ?>
        <p><strong>Skills:</strong> <?= e($reviewApplicant['skills']) ?></p>
      <?php endif; ?>
      <?php if ($reviewApplicant['bio']): ?>
        <p><strong>Bio:</strong> <?= e($reviewApplicant['bio']) ?></p>
      <?php endif; ?>

      <?php if ($reviewApplicant['resume_path']): ?>
        <p>
          <a class="btn btn-secondary btn-sm"
             href="download-resume.php?application_id=<?= (int) $reviewApplicant['application_id'] ?>">
            Download Resume
          </a>
        </p>
      <?php else: ?>
        <p class="form-hint">No resume uploaded.</p>
      <?php endif; ?>

      <form method="post" action="recruiter-dashboard.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_application">
        <input type="hidden" name="application_id" value="<?= (int) $reviewApplicant['application_id'] ?>">

        <div class="form-row">
          <div class="form-group">
            <label for="review_status">Application status</label>
            <select id="review_status" name="status">
              <?php foreach (APPLICATION_STATUSES as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= $reviewApplicant['status'] === $value ? ' selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="interview_at">Interview date &amp; time</label>
            <input type="datetime-local" id="interview_at" name="interview_at"
                   value="<?= e(datetime_local($reviewApplicant['interview_at'])) ?>">
            <div class="form-hint">Leave blank if nothing is scheduled.</div>
          </div>
        </div>

        <div class="form-group">
          <label for="review_notes">Internal notes</label>
          <textarea id="review_notes" name="notes" placeholder="Notes visible only to your team"><?= e($reviewApplicant['notes'] ?? '') ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
