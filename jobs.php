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

    // Loaded here rather than at the top so browsing pages never pull in PHPMailer.
    require_once __DIR__ . '/notifications.php';

    if ($existing) {
        if ($existing['status'] === 'withdrawn') {
            $stmt = $pdo->prepare('UPDATE applications SET status = "applied", applied_at = NOW() WHERE id = :id');
            $stmt->execute([':id' => $existing['id']]);
            notify_application_received($pdo, (int) $existing['id']);
            flash('Application re-submitted. A confirmation email is on its way.');
        } else {
            flash('You have already applied for this job.', 'error');
        }
        redirect($back);
    }

    $stmt = $pdo->prepare('INSERT INTO applications (job_id, student_id, status) VALUES (:job_id, :student_id, "applied")');
    $stmt->execute([':job_id' => $jobId, ':student_id' => $studentId]);

    // Best effort: a mail failure is logged, never surfaced as a failed application.
    notify_application_received($pdo, (int) $pdo->lastInsertId());

    flash('Application submitted successfully! Check your email for confirmation.');
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

    $pageTitle = $job['title'] . ' | CareerStudio';
    $activeNav = 'jobs';
    require __DIR__ . '/site-nav.php';
    ?>

    <main>
      <section class="container page-hero">
        <a class="label-md text-secondary" href="jobs.php">&larr; Back to all jobs</a>

        <div class="entity-head" style="margin-top: 20px;">
          <div class="entity-logo" style="width: 64px; height: 64px; font-size: 26px; background: <?= e(avatar_color($job['company_name'])) ?>;">
            <?= e(initials($job['company_name'])) ?>
          </div>
          <div>
            <h1 class="headline-lg"><?= e($job['title']) ?></h1>
            <div class="entity-sub">
              <a href="companies.php?id=<?= (int) $job['company_id'] ?>"><?= e($job['company_name']) ?></a>
              <?= $job['company_location'] ? ' &middot; ' . e($job['company_location']) : '' ?>
            </div>
          </div>
        </div>

        <div class="chip-row" style="margin-top: 20px;">
          <span class="chip chip-green"><?= e($job['work_mode']) ?></span>
          <span class="chip chip-accent"><?= e($job['job_type']) ?></span>
          <?php if ($job['location']): ?><span class="chip"><?= e($job['location']) ?></span><?php endif; ?>
          <?php if ($job['salary']): ?><span class="chip"><?= e($job['salary']) ?></span><?php endif; ?>
          <span class="chip"><?= (int) $job['vacancy_count'] ?> <?= (int) $job['vacancy_count'] === 1 ? 'vacancy' : 'vacancies' ?></span>
          <?php if ($job['deadline']): ?>
            <span class="chip">Apply by <?= e(format_date($job['deadline'])) ?></span>
          <?php endif; ?>
          <?php if ($job['status'] !== 'open'): ?><span class="chip">closed</span><?php endif; ?>
        </div>
      </section>

      <section class="container" style="padding-bottom: 64px;">
        <div class="detail-card">
          <h3 style="margin-top: 0;">Job Description</h3>
          <p class="body-md detail-text"><?= e($job['description']) ?></p>

          <?php if ($job['requirements']): ?>
            <h3>Requirements</h3>
            <p class="body-md detail-text"><?= e($job['requirements']) ?></p>
          <?php endif; ?>

          <?php if ($job['skills_required']): ?>
            <h3>Skills Required</h3>
            <div class="chip-row">
              <?php foreach (preg_split('/[,;|\/]+/', (string) $job['skills_required']) as $skill): ?>
                <?php $skill = trim($skill); ?>
                <?php if ($skill !== ''): ?>
                  <a class="chip chip-accent" href="jobs.php?title=<?= e(rawurlencode($skill)) ?>"><?= e($skill) ?></a>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <h3>About <?= e($job['company_name']) ?></h3>
          <p class="body-md text-variant"><?= e($job['company_description'] ?: 'No company description provided.') ?></p>
          <?php $site = safe_url($job['website']); ?>
          <?php if ($site): ?>
            <p style="margin-top: 8px;">
              <a class="body-sm text-secondary" href="<?= e($site) ?>" target="_blank" rel="noopener noreferrer">
                <?= e($job['website']) ?>
              </a>
            </p>
          <?php endif; ?>
          <p style="margin-top: 12px;">
            <a class="label-md text-secondary" href="companies.php?id=<?= (int) $job['company_id'] ?>">
              See all openings at <?= e($job['company_name']) ?> &rarr;
            </a>
          </p>

          <div class="detail-actions">
            <?php if (!is_logged_in()): ?>
              <a href="login.php" class="btn btn-primary">Login to Apply</a>
              <span class="label-sm text-variant">New here? <a class="text-secondary" href="register-student.php">Create a student account</a></span>
            <?php elseif (current_user_role() === 'recruiter'): ?>
              <span class="label-sm text-variant">Recruiter accounts cannot apply for jobs.</span>
            <?php else: ?>
              <?php if ($applicationStatus !== null): ?>
                <span class="status-badge">&#10003; Applied &middot; <?= e(status_label($applicationStatus)) ?></span>
              <?php elseif ($job['status'] !== 'open'): ?>
                <button class="btn btn-outline" disabled>Job Closed</button>
              <?php elseif ($deadlinePassed): ?>
                <button class="btn btn-outline" disabled>Deadline Passed</button>
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
                <button type="submit" class="btn btn-outline">
                  <?= $isSaved ? '&#9733; Saved' : '&#9734; Save to Wishlist' ?>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </section>
    </main>

    <?php
    require __DIR__ . '/site-footer.php';
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

// Headline counters for the live stat strip.
$openJobCount = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open"')->fetchColumn();
$hiringCount = (int) $pdo->query('SELECT COUNT(DISTINCT company_id) FROM jobs WHERE status = "open"')->fetchColumn();
$internshipCount = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open" AND job_type = "internship"')->fetchColumn();
$remoteCount = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open" AND work_mode = "remote"')->fetchColumn();

$pageTitle = ($savedOnly ? 'Saved Jobs' : 'Browse Jobs') . ' | CareerStudio';
$activeNav = 'jobs';
require __DIR__ . '/site-nav.php';
?>

<main>
  <section class="container page-hero">
    <span class="eyebrow">
      <span class="material-symbols-outlined" style="font-size: 14px;"><?= $savedOnly ? 'bookmark' : 'work' ?></span>
      <?= $savedOnly ? 'Your wishlist' : 'Job search' ?>
    </span>
    <h1 class="display-lg"><?= $savedOnly ? 'Your saved jobs' : 'Browse jobs &amp; internships' ?></h1>
    <p class="body-lg text-variant">
      <?= $savedOnly
            ? 'Everything you bookmarked while browsing, ready to apply when you are.'
            : 'Every open role on CareerStudio, straight from the recruiters who posted them.' ?>
    </p>

    <div class="stat-strip">
      <div class="stat-box">
        <div class="stat-value" data-live="open_jobs"><?= $openJobCount ?></div>
        <div class="label-sm text-variant">Open roles</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" data-live="hiring"><?= $hiringCount ?></div>
        <div class="label-sm text-variant">Companies hiring</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" data-live="internships"><?= $internshipCount ?></div>
        <div class="label-sm text-variant">Internships</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" data-live="remote"><?= $remoteCount ?></div>
        <div class="label-sm text-variant">Remote roles</div>
      </div>
    </div>
    <div style="margin-top: 12px;">
      <span class="live-pill"><span class="live-dot"></span> Live &middot; updated <span id="live-updated"><?= date('g:i:s A') ?></span></span>
    </div>
  </section>

  <section class="container" style="padding-bottom: 64px;">
    <form class="filter-bar filter-bar-5" method="get" action="jobs.php">
      <?php if ($savedOnly): ?><input type="hidden" name="saved" value="1"><?php endif; ?>
      <input type="text" name="title" placeholder="Job title or skill" value="<?= e($filterTitle) ?>">
      <input type="text" name="location" placeholder="Location" value="<?= e($filterLocation) ?>">
      <select name="job_type">
        <option value="">All job types</option>
        <?php foreach (['full-time' => 'Full-time', 'part-time' => 'Part-time', 'internship' => 'Internship', 'contract' => 'Contract'] as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= $filterJobType === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="work_mode">
        <option value="">Any work mode</option>
        <?php foreach (['remote' => 'Remote', 'hybrid' => 'Hybrid', 'onsite' => 'Onsite'] as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= $filterWorkMode === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">Search</button>
    </form>

    <div class="section-title">
      <h2 class="headline-md"><?= $total ?> job<?= $total === 1 ? '' : 's' ?> found</h2>
      <?php if (is_logged_in() && current_user_role() === 'student'): ?>
        <a class="label-md view-all" href="<?= $savedOnly ? 'jobs.php' : 'jobs.php?saved=1' ?>">
          <?= $savedOnly ? 'All jobs' : 'My saved jobs' ?>
          <span class="material-symbols-outlined" style="font-size: 16px;">arrow_forward</span>
        </a>
      <?php endif; ?>
    </div>

    <?php if (!$jobs): ?>
      <div class="empty-panel">
        <p class="body-md">
          <?= $savedOnly
                ? 'Your wishlist is empty. Save jobs while browsing to see them here.'
                : 'No jobs match your search. Try different filters.' ?>
        </p>
        <a class="btn btn-outline" style="margin-top: 16px;" href="jobs.php">
          <?= $savedOnly ? 'Browse all jobs' : 'Clear filters' ?>
        </a>
      </div>
    <?php else: ?>
      <div class="card-grid">
        <?php foreach ($jobs as $job): ?>
          <div class="entity-card hover-lift">
            <div class="entity-head">
              <div class="entity-logo" style="background: <?= e(avatar_color($job['company_name'])) ?>;">
                <?= e(initials($job['company_name'])) ?>
              </div>
              <div>
                <h3 class="entity-title">
                  <a href="jobs.php?id=<?= (int) $job['id'] ?>"><?= e($job['title']) ?></a>
                </h3>
                <div class="entity-sub">
                  <a href="companies.php?id=<?= (int) $job['company_id'] ?>"><?= e($job['company_name']) ?></a>
                  <?= $job['company_location'] ? ' &middot; ' . e($job['company_location']) : '' ?>
                </div>
              </div>
            </div>

            <div class="entity-body">
              <p class="entity-desc"><?= e($job['description']) ?></p>
              <div class="chip-row" style="margin-top: 12px;">
                <span class="chip chip-green"><?= e($job['work_mode']) ?></span>
                <span class="chip chip-accent"><?= e($job['job_type']) ?></span>
                <?php if ($job['location']): ?><span class="chip"><?= e($job['location']) ?></span><?php endif; ?>
                <?php if ($job['salary']): ?><span class="chip"><?= e($job['salary']) ?></span><?php endif; ?>
                <span class="chip"><?= (int) $job['vacancy_count'] ?> opening<?= (int) $job['vacancy_count'] === 1 ? '' : 's' ?></span>
              </div>
            </div>

            <div class="entity-foot">
              <span class="label-sm text-variant">
                <?= $job['deadline'] ? 'Apply by ' . e(format_date($job['deadline'])) : 'Posted ' . e(format_date($job['created_at'])) ?>
              </span>
              <span style="display: flex; gap: 8px; align-items: center;">
                <?php if (is_logged_in() && current_user_role() === 'student'): ?>
                  <?php $saved = isset($savedJobIds[$job['id']]); ?>
                  <form method="post" action="jobs.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $saved ? 'unsave' : 'save' ?>">
                    <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                    <input type="hidden" name="return_to" value="<?= e('jobs.php?' . http_build_query($_GET)) ?>">
                    <button type="submit" class="btn btn-sm btn-outline" title="<?= $saved ? 'Remove from wishlist' : 'Save to wishlist' ?>">
                      <?= $saved ? '&#9733; Saved' : '&#9734; Save' ?>
                    </button>
                  </form>
                <?php endif; ?>
                <a href="jobs.php?id=<?= (int) $job['id'] ?>" class="btn btn-sm btn-success">View &amp; Apply</a>
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="pager">
        <?php if ($page > 1): ?>
          <a class="btn btn-outline btn-sm" href="<?= e(page_url($page - 1)) ?>">&larr; Previous</a>
        <?php else: ?>
          <button class="btn btn-outline btn-sm" disabled>&larr; Previous</button>
        <?php endif; ?>
        <span class="label-sm text-variant">Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a class="btn btn-outline btn-sm" href="<?= e(page_url($page + 1)) ?>">Next &rarr;</a>
        <?php else: ?>
          <button class="btn btn-outline btn-sm" disabled>Next &rarr;</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</main>

<script src="live-stats.js" defer></script>
<?php require __DIR__ . '/site-footer.php'; ?>
