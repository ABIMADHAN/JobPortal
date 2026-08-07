<?php
/**
 * jobs.php
 * Public job browsing.
 *   GET            -> searchable, filterable, paginated list of open jobs
 *   GET ?id=123    -> single job detail (+ apply button for students)
 *   POST apply     -> student applies for a job
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pdo = get_db();

// ---------------------------------------------------------------
// POST: a student applies for a job
// ---------------------------------------------------------------
if (is_post()) {
    verify_csrf();
    require_role('student');

    $jobId = (int) ($_POST['job_id'] ?? 0);
    $studentId = (int) current_user_id();
    $action = post('action');
    // Return to whichever listing/detail page the button was clicked on.
    $back = post('return_to') !== '' ? post('return_to') : 'jobs.php?id=' . $jobId;
    if (!str_starts_with($back, 'jobs.php')) {
        $back = 'jobs.php?id=' . $jobId;
    }

    // ---- Wishlist: save / unsave a job ----
    if ($action === 'save' || $action === 'unsave') {
        if ($action === 'save') {
            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO saved_jobs (student_id, job_id) VALUES (:uid, :job)'
            );
            $stmt->execute([':uid' => $studentId, ':job' => $jobId]);
            flash('Saved to your wishlist.');
        } else {
            $stmt = $pdo->prepare('DELETE FROM saved_jobs WHERE student_id = :uid AND job_id = :job');
            $stmt->execute([':uid' => $studentId, ':job' => $jobId]);
            flash('Removed from your wishlist.');
        }
        redirect($back);
    }

    $stmt = $pdo->prepare('SELECT id, status, deadline FROM jobs WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        flash('Job not found.', 'error');
        redirect('jobs.php');
    }
    if ($job['status'] !== 'open') {
        flash('This job is no longer accepting applications.', 'error');
        redirect($back);
    }
    if ($job['deadline'] !== null && strtotime($job['deadline']) < strtotime(date('Y-m-d'))) {
        flash('The application deadline for this job has passed.', 'error');
        redirect($back);
    }

    // A resume is required before applying.
    $stmt = $pdo->prepare('SELECT resume_path FROM student_profiles WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $studentId]);
    $profile = $stmt->fetch();
    if (!$profile || empty($profile['resume_path'])) {
        flash('Please upload your resume on your profile page before applying.', 'error');
        redirect('profile.php');
    }

    $stmt = $pdo->prepare('SELECT id, status FROM applications WHERE job_id = :job_id AND student_id = :student_id LIMIT 1');
    $stmt->execute([':job_id' => $jobId, ':student_id' => $studentId]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($existing['status'] === 'withdrawn') {
            $stmt = $pdo->prepare('UPDATE applications SET status = "applied", applied_at = NOW() WHERE id = :id');
            $stmt->execute([':id' => $existing['id']]);
            flash('Application re-submitted.');
        } else {
            flash('You have already applied for this job.', 'error');
        }
        redirect($back);
    }

    $stmt = $pdo->prepare('INSERT INTO applications (job_id, student_id, status) VALUES (:job_id, :student_id, "applied")');
    $stmt->execute([':job_id' => $jobId, ':student_id' => $studentId]);

    flash('Application submitted successfully!');
    redirect($back);
}

// ---------------------------------------------------------------
// GET ?id=123 : job detail
// ---------------------------------------------------------------
if (isset($_GET['id'])) {
    $jobId = (int) $_GET['id'];

    $stmt = $pdo->prepare(
        'SELECT j.*, c.company_name, c.location AS company_location, c.website,
                c.description AS company_description
         FROM jobs j
         INNER JOIN companies c ON j.company_id = c.id
         WHERE j.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        flash('Job not found.', 'error');
        redirect('jobs.php');
    }

    // Has this student already applied, and is the job on their wishlist?
    $applicationStatus = null;
    $isSaved = false;
    if (is_logged_in() && current_user_role() === 'student') {
        $stmt = $pdo->prepare('SELECT status FROM applications WHERE job_id = :job_id AND student_id = :student_id LIMIT 1');
        $stmt->execute([':job_id' => $jobId, ':student_id' => current_user_id()]);
        $row = $stmt->fetch();
        if ($row && $row['status'] !== 'withdrawn') {
            $applicationStatus = $row['status'];
        }

        $stmt = $pdo->prepare('SELECT 1 FROM saved_jobs WHERE job_id = :job_id AND student_id = :student_id LIMIT 1');
        $stmt->execute([':job_id' => $jobId, ':student_id' => current_user_id()]);
        $isSaved = (bool) $stmt->fetchColumn();
    }

    $deadlinePassed = $job['deadline'] !== null && strtotime($job['deadline']) < strtotime(date('Y-m-d'));

    $pageTitle = $job['title'];
    $topbarTitle = $job['title'];
    $activeNav = 'jobs';
    // Signed-in visitors keep the app shell; guests get the public navbar.
    $layout = is_logged_in() ? 'app' : 'site';
    require __DIR__ . '/header.php';
    ?>

    <main class="<?= $layout === 'app' ? 'canvas' : 'container' ?>" style="padding-top:32px; padding-bottom:60px;">
      <a href="jobs.php">&larr; Back to all jobs</a>

      <div class="card" style="margin-top:16px;">
        <div class="flex-between" style="align-items:flex-start; flex-wrap:wrap; gap:16px;">
          <div>
            <h2 style="margin-bottom:4px;"><?= e($job['title']) ?></h2>
            <div class="company" style="margin-bottom:0;">
              <?= e($job['company_name']) ?><?= $job['company_location'] ? ' · ' . e($job['company_location']) : '' ?>
            </div>
          </div>
          <span class="badge badge-<?= e($job['status']) ?>"><?= e($job['status']) ?></span>
        </div>

        <div class="job-meta" style="margin:16px 0;">
          <span class="badge"><?= e($job['job_type']) ?></span>
          <span class="badge"><?= e($job['work_mode']) ?></span>
          <?php if ($job['location']): ?><span class="badge"><?= e($job['location']) ?></span><?php endif; ?>
          <?php if ($job['salary']): ?><span class="badge"><?= e($job['salary']) ?></span><?php endif; ?>
          <span class="badge"><?= (int) $job['vacancy_count'] ?> <?= (int) $job['vacancy_count'] === 1 ? 'vacancy' : 'vacancies' ?></span>
          <?php if ($job['deadline']): ?>
            <span class="badge">Deadline: <?= e(format_date($job['deadline'])) ?></span>
          <?php endif; ?>
        </div>

        <h3>Job Description</h3>
        <p style="white-space:pre-wrap;"><?= e($job['description']) ?></p>

        <?php if ($job['requirements']): ?>
          <h3>Requirements</h3>
          <p style="white-space:pre-wrap;"><?= e($job['requirements']) ?></p>
        <?php endif; ?>

        <?php if ($job['skills_required']): ?>
          <h3>Skills Required</h3>
          <p><?= e($job['skills_required']) ?></p>
        <?php endif; ?>

        <h3>About <?= e($job['company_name']) ?></h3>
        <p><?= e($job['company_description'] ?: 'No company description provided.') ?></p>
        <?php $site = safe_url($job['website']); ?>
        <?php if ($site): ?>
          <p><a href="<?= e($site) ?>" target="_blank" rel="noopener noreferrer"><?= e($job['website']) ?></a></p>
        <?php endif; ?>

        <div class="flex gap-8" style="margin-top:20px; flex-wrap:wrap; align-items:center;">
          <?php if (!is_logged_in()): ?>
            <a href="login.php" class="btn btn-primary">Login to Apply</a>
          <?php elseif (current_user_role() === 'recruiter'): ?>
            <p class="form-hint mb-0">Recruiter accounts cannot apply for jobs.</p>
          <?php else: ?>
            <?php if ($applicationStatus !== null): ?>
              <span class="badge badge-<?= e($applicationStatus) ?>">Applied · <?= e(status_label($applicationStatus)) ?></span>
            <?php elseif ($job['status'] !== 'open'): ?>
              <button class="btn btn-secondary" disabled>Job Closed</button>
            <?php elseif ($deadlinePassed): ?>
              <button class="btn btn-secondary" disabled>Deadline Passed</button>
            <?php else: ?>
              <form method="post" action="jobs.php">
                <?= csrf_field() ?>
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <button type="submit" class="btn btn-primary">Apply Now</button>
              </form>
            <?php endif; ?>

            <!-- Wishlist toggle: feeds the Wishlist column on the dashboard -->
            <form method="post" action="jobs.php">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= $isSaved ? 'unsave' : 'save' ?>">
              <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
              <input type="hidden" name="return_to" value="jobs.php?id=<?= (int) $job['id'] ?>">
              <button type="submit" class="btn btn-secondary">
                <?= $isSaved ? '★ Saved' : '☆ Save to Wishlist' ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </main>

    <?php
    require __DIR__ . '/footer.php';
    exit;
}

// ---------------------------------------------------------------
// GET : job listing with search / filters / pagination
// ---------------------------------------------------------------
$filterTitle = query('title');
$filterLocation = query('location');
$filterJobType = query('job_type');
$filterWorkMode = query('work_mode');

// ?saved=1 narrows the list to the student's wishlist.
$savedOnly = isset($_GET['saved']) && is_logged_in() && current_user_role() === 'student';

$where = ['j.status = "open"'];
$params = [];

if ($savedOnly) {
    $where[] = 'j.id IN (SELECT job_id FROM saved_jobs WHERE student_id = :saved_uid)';
    $params[':saved_uid'] = (int) current_user_id();
}

if ($filterTitle !== '') {
    // Two placeholders on purpose: native prepared statements (EMULATE_PREPARES
    // off) reject the same named parameter appearing twice in one statement.
    $where[] = '(j.title LIKE :title_a OR j.skills_required LIKE :title_b)';
    $params[':title_a'] = '%' . $filterTitle . '%';
    $params[':title_b'] = '%' . $filterTitle . '%';
}
if ($filterLocation !== '') {
    $where[] = 'j.location LIKE :location';
    $params[':location'] = '%' . $filterLocation . '%';
}
if (in_array($filterJobType, ['full-time', 'part-time', 'internship', 'contract'], true)) {
    $where[] = 'j.job_type = :job_type';
    $params[':job_type'] = $filterJobType;
} else {
    $filterJobType = '';
}
if (in_array($filterWorkMode, ['remote', 'hybrid', 'onsite'], true)) {
    $where[] = 'j.work_mode = :work_mode';
    $params[':work_mode'] = $filterWorkMode;
} else {
    $filterWorkMode = '';
}

$whereSql = implode(' AND ', $where);
[$page, $limit, $offset] = get_pagination($_GET);

$stmt = $pdo->prepare(
    "SELECT j.*, c.company_name, c.location AS company_location
     FROM jobs j
     INNER JOIN companies c ON j.company_id = c.id
     WHERE $whereSql
     ORDER BY j.created_at DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$jobs = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS total FROM jobs j INNER JOIN companies c ON j.company_id = c.id WHERE $whereSql"
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->execute();
$total = (int) $stmt->fetch()['total'];
$totalPages = (int) max(1, ceil($total / $limit));

// Which of the jobs on this page are already on the student's wishlist?
$savedJobIds = [];
if ($jobs && is_logged_in() && current_user_role() === 'student') {
    $ids = array_map('intval', array_column($jobs, 'id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT job_id FROM saved_jobs WHERE student_id = ? AND job_id IN ($placeholders)");
    $stmt->execute(array_merge([(int) current_user_id()], $ids));
    $savedJobIds = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Builds a link to another page while keeping the active filters. */
function page_link(int $targetPage): string
{
    $qs = $_GET;
    $qs['page'] = $targetPage;
    return 'jobs.php?' . http_build_query($qs);
}

$pageTitle = $savedOnly ? 'Saved Jobs' : 'Browse Jobs';
$topbarTitle = $savedOnly ? 'Saved Jobs' : 'Browse Jobs';
$activeNav = $savedOnly ? 'saved' : 'jobs';
// Signed-in visitors keep the app shell; guests get the public navbar.
$layout = is_logged_in() ? 'app' : 'site';
require __DIR__ . '/header.php';
?>

<main class="<?= $layout === 'app' ? 'canvas' : 'container' ?>" style="padding-top:32px; padding-bottom:60px;">
  <h1><?= $savedOnly ? 'Your Saved Jobs' : 'Browse Jobs &amp; Internships' ?></h1>

  <form class="filters-bar card" method="get" action="jobs.php">
    <?php if ($savedOnly): ?><input type="hidden" name="saved" value="1"><?php endif; ?>
    <input type="text" name="title" placeholder="Job title or skill" value="<?= e($filterTitle) ?>">
    <input type="text" name="location" placeholder="Location" value="<?= e($filterLocation) ?>">
    <select name="job_type">
      <option value="">All Job Types</option>
      <?php foreach (['full-time' => 'Full-time', 'part-time' => 'Part-time', 'internship' => 'Internship', 'contract' => 'Contract'] as $value => $label): ?>
        <option value="<?= e($value) ?>"<?= $filterJobType === $value ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="work_mode">
      <option value="">Any Work Mode</option>
      <?php foreach (['remote' => 'Remote', 'hybrid' => 'Hybrid', 'onsite' => 'Onsite'] as $value => $label): ?>
        <option value="<?= e($value) ?>"<?= $filterWorkMode === $value ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Search</button>
  </form>

  <?php if (!$jobs): ?>
    <div class="empty-state">
      <?= $savedOnly
            ? 'Your wishlist is empty. Save jobs while browsing to see them here.'
            : 'No jobs match your search. Try different filters.' ?>
    </div>
  <?php else: ?>
    <div class="job-list">
      <?php foreach ($jobs as $job): ?>
        <div class="card job-card">
          <div class="job-card-main">
            <h3><a href="jobs.php?id=<?= (int) $job['id'] ?>"><?= e($job['title']) ?></a></h3>
            <div class="company">
              <?= e($job['company_name']) ?><?= $job['company_location'] ? ' · ' . e($job['company_location']) : '' ?>
            </div>
            <p style="margin-bottom:0;">
              <?= e(mb_substr((string) $job['description'], 0, 140)) ?><?= mb_strlen((string) $job['description']) > 140 ? '…' : '' ?>
            </p>
            <div class="job-meta">
              <span class="badge"><?= e($job['job_type']) ?></span>
              <span class="badge"><?= e($job['work_mode']) ?></span>
              <?php if ($job['location']): ?><span class="badge"><?= e($job['location']) ?></span><?php endif; ?>
              <?php if ($job['salary']): ?><span class="badge"><?= e($job['salary']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="job-card-side">
            <a href="jobs.php?id=<?= (int) $job['id'] ?>" class="btn btn-primary btn-sm">View Details</a>
            <?php if (is_logged_in() && current_user_role() === 'student'): ?>
              <?php $saved = isset($savedJobIds[$job['id']]); ?>
              <form method="post" action="jobs.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $saved ? 'unsave' : 'save' ?>">
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="return_to" value="<?= e('jobs.php?' . http_build_query($_GET)) ?>">
                <button type="submit" class="btn btn-secondary btn-sm"><?= $saved ? '★ Saved' : '☆ Save' ?></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="flex-between" style="margin-top:20px;">
      <?php if ($page > 1): ?>
        <a class="btn btn-secondary btn-sm" href="<?= e(page_link($page - 1)) ?>">&larr; Previous</a>
      <?php else: ?>
        <button class="btn btn-secondary btn-sm" disabled>&larr; Previous</button>
      <?php endif; ?>

      <span style="color:var(--color-text-faint); font-size:0.85rem;">
        Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> job<?= $total === 1 ? '' : 's' ?> found
      </span>

      <?php if ($page < $totalPages): ?>
        <a class="btn btn-secondary btn-sm" href="<?= e(page_link($page + 1)) ?>">Next &rarr;</a>
      <?php else: ?>
        <button class="btn btn-secondary btn-sm" disabled>Next &rarr;</button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/footer.php'; ?>
