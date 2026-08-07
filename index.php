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

// Companies with at least one open role — matches the "Companies hiring" label
// and the "hiring" counter that live-stats.php refreshes.
$stmt = $pdo->query('SELECT COUNT(DISTINCT company_id) AS total FROM jobs WHERE status = "open"');
$companyCount = (int) $stmt->fetch()['total'];

$stmt = $pdo->query('SELECT COUNT(*) AS total FROM users WHERE role = "student"');
$studentCount = (int) $stmt->fetch()['total'];

$pageTitle = 'CareerStudio | Premium Job Portal';
$activeNav = 'jobs';
require __DIR__ . '/site-nav.php';
?>

  <main>
    <!-- Hero Section -->
    <section class="hero section-padding">
      <div class="hero-bg-blur"></div>
      <div class="container hero-grid">
        <!-- Left Side: Copy and Search Component -->
        <div class="hero-content">
          <div>
            <h1 class="display-lg hero-title">Launch your career with the right job or internship</h1>
            <p class="body-lg text-variant">CareerStudio connects students with recruiters hiring for full-time roles,
              internships, and everything in between. Your job search, organized and accelerated.</p>
          </div>
          <div class="hero-actions">
            <a href="register-student.php" class="btn btn-dark soft-shadow">I'm a Student</a>
            <a href="register-recruiter.php" class="btn btn-outline">I'm a Recruiter</a>
          </div>
          <!-- Hero Search Component (Dynamically pointing to jobs.php) -->
          <form action="jobs.php" method="get" class="search-box soft-shadow">
            <div class="search-grid">
              <div class="input-group">
                <span class="material-symbols-outlined">search</span>
                <input class="body-md" name="title" placeholder="Job title, Skills, Company" type="text">
              </div>
              <div class="input-group">
                <span class="material-symbols-outlined">location_on</span>
                <input class="body-md" name="location" placeholder="Location or Remote" type="text">
              </div>
              <div class="search-actions input-group">
                <select name="experience" class="body-md text-variant">
                  <option value="">Any Experience</option>
                  <option value="entry">Entry Level</option>
                  <option value="mid">Mid Level</option>
                  <option value="senior">Senior Level</option>
                </select>
                <button type="submit" class="btn btn-primary">Search</button>
              </div>
            </div>
            <div class="trending">
              <span class="label-sm text-variant" style="font-weight: 700;">Trending:</span>
              <span class="tag">React</span>
              <span class="tag">Python</span>
              <span class="tag">Product Manager</span>
              <span class="tag">Remote</span>
            </div>
          </form>
          <!-- Trust Indicators (Populated from DB) -->
          <div class="stats-grid">
            <div>
              <div class="headline-md text-secondary"><span data-live="open_jobs"><?= $openJobCount ?></span>+</div>
              <div class="label-sm text-variant" style="margin-top: 4px;">Open roles</div>
            </div>
            <div>
              <div class="headline-md text-secondary"><span data-live="hiring"><?= $companyCount ?></span>+</div>
              <div class="label-sm text-variant" style="margin-top: 4px;">Companies hiring</div>
            </div>
            <div>
              <div class="headline-md text-secondary"><span data-live="students"><?= $studentCount ?></span>+</div>
              <div class="label-sm text-variant" style="margin-top: 4px;">Students registered</div>
            </div>
            <div>
              <div class="headline-md text-secondary">Live</div>
              <div class="label-sm text-variant" style="margin-top: 4px;">Updated <span id="live-updated"><?= date('g:i:s A') ?></span></div>
            </div>
          </div>
        </div>

        <!-- Right Side: Dashboard Preview (Static UI element) -->
        <div class="dashboard-wrapper">
          <div class="dashboard-backdrop"></div>
          <div class="dashboard-card soft-shadow">
            <div class="dash-header">
              <div class="dash-user">
                <div class="avatar">
                  <img alt="User Profile"
                    src="https://lh3.googleusercontent.com/aida-public/AB6AXuAr3pdPHzgK2hY3UGAUcy-uWLekxavX-SAL8oAeQfd6jazAyiIYp6p5Rmx4N8BPSJ38dpkzdgD9rV_mNsjk1SyqAd3hWPkupGJNmx8CjVOWtAttVbBb3MnDnKFdOd_RIVkArg0zdrBs3apSVj_wnR2fr_keACwh7gu_n9jr6CRJSBIcqJo-NR36k5mylo_glkOQyIn7-5rfPqIBDFeOUM0R_omkEleVYZoCY5TBHYjiIw_VFsh3GGIZ">
                </div>
                <div>
                  <div class="label-md" style="font-weight: 700;">Welcome back, Alex</div>
                  <div class="label-sm text-variant">Profile 95% complete</div>
                </div>
              </div>
              <div class="badge-ai">
                <span class="material-symbols-outlined" style="font-size: 14px;">bolt</span> AI Active
              </div>
            </div>
            <div class="dash-stats">
              <div class="stat-box hover-lift">
                <div class="label-sm text-variant" style="margin-bottom: 4px;">AI Match Score</div>
                <div class="display-lg-mobile" style="color: #0F172A;">98%</div>
                <div class="label-sm"
                  style="color: var(--success); display: flex; align-items: center; margin-top: 4px;">
                  <span class="material-symbols-outlined" style="font-size: 12px; margin-right: 4px;">trending_up</span>
                  Top 5%
                </div>
              </div>
              <div class="stat-box hover-lift">
                <div class="label-sm text-variant" style="margin-bottom: 4px;">Applications</div>
                <div class="display-lg-mobile" style="color: #0F172A;">12</div>
                <div class="label-sm"
                  style="color: var(--secondary); display: flex; align-items: center; margin-top: 4px;">
                  3 interviews
                </div>
              </div>
            </div>
            <div>
              <div class="label-md"
                style="font-weight: 700; margin-bottom: 12px; display: flex; justify-content: space-between;">
                Top AI Recommendations
                <span class="material-symbols-outlined text-variant" style="font-size: 16px;">more_horiz</span>
              </div>
              <div class="job-list">
                <div class="job-item">
                  <div style="display: flex; align-items: center; gap: 12px;">
                    <div class="job-icon" style="background: #000;">
                      <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path d="M13 10V3L4 14h7v7l9-11h-7z" stroke-linecap="round" stroke-linejoin="round"
                          stroke-width="2"></path>
                      </svg>
                    </div>
                    <div>
                      <div class="label-sm" style="font-weight: 700;">Senior Product Designer</div>
                      <div class="label-sm text-variant" style="font-size: 10px;">Vercel &bull; Remote</div>
                    </div>
                  </div>
                  <div class="match-badge">99% Match</div>
                </div>
                <div class="job-item">
                  <div style="display: flex; align-items: center; gap: 12px;">
                    <div class="job-icon" style="background: #6366F1;">
                      <span class="material-symbols-outlined" style="font-size: 20px;">payments</span>
                    </div>
                    <div>
                      <div class="label-sm" style="font-weight: 700;">Staff Engineer, Platform</div>
                      <div class="label-sm text-variant" style="font-size: 10px;">Stripe &bull; Hybrid</div>
                    </div>
                  </div>
                  <div class="match-badge">96% Match</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Manage Pipeline Section -->
    <section class="bg-gray-section section-padding">
      <div class="container">
        <div class="text-center" style="margin-bottom: 48px;">
          <h2 class="headline-lg" style="margin-bottom: 16px;">Manage Your Pipeline</h2>
          <p class="body-lg text-variant" style="max-width: 672px; margin: 0 auto;">Track every application, interview,
            and offer effortlessly with our intuitive pipeline management tools.</p>
        </div>
        <div class="pipeline-image-wrapper soft-shadow">
          <img src="screen.png" />
        </div>
      </div>
    </section>

    <!-- Featured Jobs Section (Dynamic from Database) -->
    <?php if ($latestJobs): ?>
      <section class="container section-padding">
        <div class="section-header">
          <div>
            <h2 class="headline-lg" style="margin-bottom: 8px;">Latest Openings</h2>
            <p class="body-md text-variant">Hand-picked opportunities matched to your profile.</p>
          </div>
          <a class="label-md view-all" href="jobs.php">
            View all jobs <span class="material-symbols-outlined" style="font-size: 16px;">arrow_forward</span>
          </a>
        </div>

        <div class="job-grid">
          <?php foreach ($latestJobs as $job): ?>
            <div class="job-card hover-lift">
              <div>
                <div class="job-card-header">
                  <div class="job-company">
                    <div class="company-logo">
                      <span class="material-symbols-outlined text-variant">work</span>
                    </div>
                    <div>
                      <h3 class="label-md" style="font-weight: 700;">
                        <a href="jobs.php?id=<?= (int) $job['id'] ?>"><?= e($job['title']) ?></a>
                      </h3>
                      <p class="label-sm text-variant"><?= e($job['company_name']) ?></p>
                    </div>
                  </div>
                </div>
                <div class="job-tags">
                  <span class="tag-green"><?= e($job['work_mode']) ?></span>
                  <span class="tag-gray"><?= e($job['job_type']) ?></span>
                </div>
              </div>
              <div class="job-footer">
                <div class="label-sm text-variant" style="display: flex; align-items: center; gap: 4px;">
                  <span class="material-symbols-outlined" style="font-size: 14px;">location_on</span>
                  <?= $job['location'] ? e($job['location']) : 'Remote' ?>
                </div>
                <a href="jobs.php?id=<?= (int) $job['id'] ?>" class="btn btn-sm btn-success label-sm">View Details</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <a class="label-md view-all-mobile" href="jobs.php">
          View all jobs <span class="material-symbols-outlined" style="font-size: 16px;">arrow_forward</span>
        </a>
      </section>
    <?php endif; ?>
  </main>

  <script src="live-stats.js" defer></script>
<?php require __DIR__ . '/site-footer.php'; ?>
