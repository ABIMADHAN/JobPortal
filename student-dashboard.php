<?php
/**
 * student-dashboard.php
 * Application pipeline board. Columns are Wishlist (saved jobs) plus the four
 * application stages. Side widgets show upcoming interviews and the hire rate.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_role('student');

$pdo = get_db();
$studentId = (int) current_user_id();

// ---------------------------------------------------------------
// POST: withdraw an application, or drop a job from the wishlist
// ---------------------------------------------------------------
if (is_post()) {
    verify_csrf();
    $action = post('action');

    if ($action === 'unsave') {
        $stmt = $pdo->prepare('DELETE FROM saved_jobs WHERE student_id = :uid AND job_id = :job');
        $stmt->execute([':uid' => $studentId, ':job' => (int) ($_POST['job_id'] ?? 0)]);
        flash('Removed from your wishlist.');
        redirect('student-dashboard.php');
    }

    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $stmt = $pdo->prepare(
        'UPDATE applications SET status = "withdrawn"
         WHERE id = :id AND student_id = :student_id AND status != "hired"'
    );
    $stmt->execute([':id' => $applicationId, ':student_id' => $studentId]);

    flash(
        $stmt->rowCount() === 0
            ? 'Application not found, or it can no longer be withdrawn.'
            : 'Application withdrawn.',
        $stmt->rowCount() === 0 ? 'error' : 'success'
    );
    redirect('student-dashboard.php');
}

// ---------------------------------------------------------------
// Stats
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_applications,
        SUM(CASE WHEN status IN ("applied", "under_review") THEN 1 ELSE 0 END) AS pending_applications,
        SUM(CASE WHEN status = "shortlisted" THEN 1 ELSE 0 END) AS shortlisted_applications,
        SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) AS rejected_applications,
        SUM(CASE WHEN status = "hired" THEN 1 ELSE 0 END) AS hired_applications
     FROM applications WHERE student_id = :student_id AND status != "withdrawn"'
);
$stmt->execute([':student_id' => $studentId]);
$stats = $stmt->fetch() ?: [];

$totalApplications = (int) ($stats['total_applications'] ?? 0);
$hiredApplications = (int) ($stats['hired_applications'] ?? 0);
$successRate = $totalApplications > 0 ? (int) round($hiredApplications / $totalApplications * 100) : 0;

// SVG donut: r=40, so the circumference is 2*pi*r ≈ 251.2.
$donutCircumference = 251.2;
$donutOffset = $donutCircumference - ($donutCircumference * $successRate / 100);

$stmt = $pdo->prepare('SELECT resume_path FROM student_profiles WHERE user_id = :uid LIMIT 1');
$stmt->execute([':uid' => $studentId]);
$profileRow = $stmt->fetch();
$hasResume = $profileRow && !empty($profileRow['resume_path']);

// ---------------------------------------------------------------
// Board data
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT s.job_id, j.title, j.job_type, c.company_name
     FROM saved_jobs s
     INNER JOIN jobs j ON s.job_id = j.id
     INNER JOIN companies c ON j.company_id = c.id
     WHERE s.student_id = :uid
     ORDER BY s.created_at DESC'
);
$stmt->execute([':uid' => $studentId]);
$savedJobs = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT a.id, a.status, a.applied_at, a.interview_at, a.job_id, j.title, c.company_name
     FROM applications a
     INNER JOIN jobs j ON a.job_id = j.id
     INNER JOIN companies c ON j.company_id = c.id
     WHERE a.student_id = :student_id
     ORDER BY a.applied_at DESC'
);
$stmt->execute([':student_id' => $studentId]);
$applications = $stmt->fetchAll();

$columns = [
    'applied'   => ['label' => 'Applied',       'items' => []],
    'interview' => ['label' => 'Interviewing',  'items' => []],
    'offer'     => ['label' => 'Offer / Hired', 'items' => []],
    'closed'    => ['label' => 'Closed',        'items' => []],
];

foreach ($applications as $app) {
    $key = match ($app['status']) {
        'under_review', 'shortlisted' => 'interview',
        'hired' => 'offer',
        'rejected', 'withdrawn' => 'closed',
        default => 'applied',
    };
    $columns[$key]['items'][] = $app;
}

$interviews = upcoming_interviews($pdo);

$pageTitle = 'Student Dashboard';
$topbarTitle = 'Job Application Tracker';
$activeNav = 'dashboard';
$layout = 'app';
require __DIR__ . '/header.php';
?>

<main class="canvas">

  <div class="canvas-header">
    <div>
      <h2 class="canvas-title">Application Pipeline</h2>
      <p class="canvas-sub">Track every application from wishlist to offer.</p>
    </div>
    <div class="canvas-actions">
      <a href="export.php" class="btn btn-secondary"><?= nav_icon('export') ?> Export CSV</a>
      <a href="jobs.php" class="btn btn-primary"><?= nav_icon('add') ?> Find Jobs</a>
    </div>
  </div>

  <?php if (!$hasResume): ?>
    <div class="alert alert-error show">
      You haven't uploaded a resume yet — you'll need one before you can apply.
      <a href="profile.php">Upload it on your profile &rarr;</a>
    </div>
  <?php endif; ?>

  <div class="stat-strip">
    <div class="stat-chip"><span class="stat-chip-value"><?= count($savedJobs) ?></span><span class="stat-chip-label">Wishlist</span></div>
    <div class="stat-chip"><span class="stat-chip-value"><?= $totalApplications ?></span><span class="stat-chip-label">Applications</span></div>
    <div class="stat-chip"><span class="stat-chip-value"><?= (int) ($stats['pending_applications'] ?? 0) ?></span><span class="stat-chip-label">In Review</span></div>
    <div class="stat-chip"><span class="stat-chip-value"><?= (int) ($stats['shortlisted_applications'] ?? 0) ?></span><span class="stat-chip-label">Shortlisted</span></div>
    <div class="stat-chip"><span class="stat-chip-value"><?= $hiredApplications ?></span><span class="stat-chip-label">Hired</span></div>
    <div class="stat-chip"><span class="stat-chip-value"><?= count($interviews) ?></span><span class="stat-chip-label">Interviews</span></div>
  </div>

  <div class="board">

    <!-- Wishlist column: saved jobs the student hasn't applied to yet -->
    <section class="board-col">
      <div class="board-col-head">
        <h3>Wishlist <span class="board-count"><?= count($savedJobs) ?></span></h3>
      </div>
      <div class="board-col-body">
        <?php if (!$savedJobs): ?>
          <p class="board-empty">Nothing saved yet. Bookmark jobs while browsing.</p>
        <?php endif; ?>

        <?php foreach ($savedJobs as $job): ?>
          <article class="board-card">
            <div class="board-card-company"><?= e($job['company_name']) ?></div>
            <h4 class="board-card-title">
              <a href="jobs.php?id=<?= (int) $job['job_id'] ?>"><?= e($job['title']) ?></a>
            </h4>
            <div class="board-card-foot">
              <span class="badge"><?= e($job['job_type']) ?></span>
              <form method="post" action="student-dashboard.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="unsave">
                <input type="hidden" name="job_id" value="<?= (int) $job['job_id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Remove</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Application stage columns -->
    <?php foreach ($columns as $key => $column): ?>
      <section class="board-col<?= $key === 'interview' ? ' board-col-accent' : '' ?>">
        <div class="board-col-head">
          <h3><?= e($column['label']) ?> <span class="board-count"><?= count($column['items']) ?></span></h3>
        </div>
        <div class="board-col-body">
          <?php if (!$column['items']): ?>
            <p class="board-empty">Nothing here yet.</p>
          <?php endif; ?>

          <?php foreach ($column['items'] as $app): ?>
            <article class="board-card<?= $key === 'interview' ? ' board-card-accent' : '' ?>">
              <div class="board-card-company">
                <?= e($app['company_name']) ?> &middot; <?= e(format_date($app['applied_at'])) ?>
              </div>
              <h4 class="board-card-title">
                <a href="jobs.php?id=<?= (int) $app['job_id'] ?>"><?= e($app['title']) ?></a>
              </h4>

              <?php if ($app['interview_at'] && strtotime($app['interview_at']) >= time()): ?>
                <div class="board-card-note">Interview <?= e(format_datetime($app['interview_at'])) ?></div>
              <?php endif; ?>

              <div class="board-card-foot">
                <span class="badge badge-<?= e($app['status']) ?>"><?= e(status_label($app['status'])) ?></span>
                <?php if (in_array($app['status'], ['applied', 'under_review', 'shortlisted'], true)): ?>
                  <form method="post" action="student-dashboard.php"
                        onsubmit="return confirm('Withdraw this application?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Withdraw</button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

  </div>

  <!-- Floating widgets -->
  <div class="widget-dock">
    <section class="widget widget-interviews">
      <h4>Upcoming Interviews</h4>
      <?php if (!$interviews): ?>
        <p class="widget-empty">No interviews scheduled yet.</p>
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
      <h4>Success Rate</h4>
      <div class="donut-wrapper">
        <svg class="donut-svg" viewBox="0 0 100 100">
          <circle class="donut-bg" cx="50" cy="50" r="40"></circle>
          <circle class="donut-progress" cx="50" cy="50" r="40"
                  stroke-dasharray="<?= $donutCircumference ?>"
                  stroke-dashoffset="<?= $donutOffset ?>"></circle>
        </svg>
        <div class="donut-text"><?= $successRate ?>%</div>
      </div>
      <p class="widget-empty"><?= $hiredApplications ?> of <?= $totalApplications ?> hired</p>
    </section>
  </div>

</main>

<?php require __DIR__ . '/footer.php'; ?>
