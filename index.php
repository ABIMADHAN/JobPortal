<?php
/**
 * index.php
 * Public landing page. Shows the search box, feature highlights and the
 * newest open jobs pulled straight from the database.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pdo = get_db();

// Newest open jobs for the "Latest openings" strip.
$stmt = $pdo->query(
    'SELECT j.id, j.title, j.job_type, j.work_mode, j.location, c.company_name
     FROM jobs j
     INNER JOIN companies c ON j.company_id = c.id
     WHERE j.status = "open"
     ORDER BY j.created_at DESC
     LIMIT 4'
);
$latestJobs = $stmt->fetchAll();

$stmt = $pdo->query('SELECT COUNT(*) AS total FROM jobs WHERE status = "open"');
$openJobCount = (int) $stmt->fetch()['total'];

$stmt = $pdo->query('SELECT COUNT(*) AS total FROM companies');
$companyCount = (int) $stmt->fetch()['total'];

$stmt = $pdo->query('SELECT COUNT(*) AS total FROM users WHERE role = "student"');
$studentCount = (int) $stmt->fetch()['total'];

$pageTitle = 'Find Jobs & Internships';
require __DIR__ . '/header.php';
?>

<section class="hero">
  <div class="container">
    <h1>Launch your career with the right job or internship</h1>
    <p>
      JobPortal connects students with recruiters hiring for full-time roles, internships,
      and everything in between. Your job search, organized and accelerated.
    </p>

    <form class="hero-search" method="get" action="jobs.php">
      <input type="text" name="title" placeholder="Search by job title, skill or keyword...">
      <button type="submit" class="btn btn-primary">Search Jobs</button>
    </form>

    <div class="hero-actions">
      <a href="register-student.php" class="btn btn-primary">I'm a Student</a>
      <a href="register-recruiter.php" class="btn btn-secondary">I'm a Recruiter</a>
    </div>

    <div class="hero-stats">
      <div><strong><?= $openJobCount ?></strong><span>Open roles</span></div>
      <div><strong><?= $companyCount ?></strong><span>Companies hiring</span></div>
      <div><strong><?= $studentCount ?></strong><span>Students registered</span></div>
    </div>

    <div class="hero-image">
      <img src="dashboard.png" alt="JobPortal dashboard preview">
    </div>
  </div>
</section>

<section class="container">
  <div class="features">
    <div class="card feature-card">
      <div class="feature-icon">&#128188;</div>
      <h3>Browse verified jobs</h3>
      <p class="mb-0">Explore full-time, part-time, internship and contract roles posted directly by recruiters.</p>
    </div>
    <div class="card feature-card">
      <div class="feature-icon">&#128228;</div>
      <h3>Apply in one click</h3>
      <p class="mb-0">Build your profile once, upload your resume, and apply to any open job instantly.</p>
    </div>
    <div class="card feature-card">
      <div class="feature-icon">&#128202;</div>
      <h3>Track your progress</h3>
      <p class="mb-0">Follow every application from applied to shortlisted to hired on your dashboard.</p>
    </div>
  </div>
</section>

<?php if ($latestJobs): ?>
<section class="container" style="padding-bottom:48px;">
  <div class="flex-between" style="margin-bottom:16px;">
    <h2 class="mb-0">Latest openings</h2>
    <a href="jobs.php">View all jobs &rarr;</a>
  </div>
  <div class="job-list">
    <?php foreach ($latestJobs as $job): ?>
      <div class="card job-card">
        <div class="job-card-main">
          <h3><a href="jobs.php?id=<?= (int) $job['id'] ?>"><?= e($job['title']) ?></a></h3>
          <div class="company"><?= e($job['company_name']) ?></div>
          <div class="job-meta">
            <span class="badge"><?= e($job['job_type']) ?></span>
            <span class="badge"><?= e($job['work_mode']) ?></span>
            <?php if ($job['location']): ?><span class="badge"><?= e($job['location']) ?></span><?php endif; ?>
          </div>
        </div>
        <div class="job-card-side">
          <a href="jobs.php?id=<?= (int) $job['id'] ?>" class="btn btn-secondary btn-sm">View Details</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="container">
  <div class="card cta-card">
    <h2>Hiring? Post a job in minutes</h2>
    <p>Create a company profile, publish openings, and manage applicants — all in one place.</p>
    <a href="register-recruiter.php" class="btn btn-primary">Post a Job</a>
  </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>
