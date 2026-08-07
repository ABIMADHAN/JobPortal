<?php
/**
 * resources.php
 * Career resource hub. Nothing here is hard-coded market data — every number is
 * derived from what recruiters have actually posted in this portal:
 *
 *   - live market pulse (open roles, posted this week, deadlines closing)
 *   - skills in demand, parsed out of jobs.skills_required
 *   - where the jobs are (top locations) and what shape they take (type/mode)
 *   - top hiring companies and roles closing soon
 *   - a readiness checklist personalised to whoever is signed in
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pdo = get_db();

// ---- Live market pulse ----
$openJobs = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open"')->fetchColumn();
$postedWeek = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open" AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();
$closingSoonCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM jobs
     WHERE status = "open" AND deadline IS NOT NULL
       AND deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)'
)->fetchColumn();
$studentCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "student"')->fetchColumn();

// ---- Skills in demand ----
// skills_required is a free-text list ("PHP, MySQL, React"), so split it in PHP
// and tally the pieces rather than pretending the column is normalised.
$skillCounts = [];
$skillLabels = [];
foreach ($pdo->query('SELECT skills_required FROM jobs WHERE status = "open" AND skills_required IS NOT NULL') as $row) {
    foreach (preg_split('/[,;|\/]+/', (string) $row['skills_required']) as $skill) {
        $skill = trim($skill);
        if ($skill === '' || mb_strlen($skill) > 40) {
            continue;
        }
        $key = mb_strtolower($skill);
        $skillCounts[$key] = ($skillCounts[$key] ?? 0) + 1;
        $skillLabels[$key] = $skillLabels[$key] ?? $skill; // first spelling wins
    }
}
arsort($skillCounts);
$topSkills = array_slice($skillCounts, 0, 10, true);
$maxSkill = $topSkills ? max($topSkills) : 1;

// ---- Where the jobs are ----
$topLocations = $pdo->query(
    'SELECT location, COUNT(*) AS total
     FROM jobs
     WHERE status = "open" AND location IS NOT NULL AND location <> ""
     GROUP BY location
     ORDER BY total DESC, location ASC
     LIMIT 8'
)->fetchAll();

// ---- Shape of the market ----
$byType = $pdo->query(
    'SELECT job_type, COUNT(*) AS total FROM jobs WHERE status = "open" GROUP BY job_type ORDER BY total DESC'
)->fetchAll();
$byMode = $pdo->query(
    'SELECT work_mode, COUNT(*) AS total FROM jobs WHERE status = "open" GROUP BY work_mode ORDER BY total DESC'
)->fetchAll();

// ---- Top hiring companies ----
$topCompanies = $pdo->query(
    'SELECT c.id, c.company_name, COUNT(j.id) AS open_roles,
            COALESCE(SUM(j.vacancy_count), 0) AS vacancies
     FROM companies c
     INNER JOIN jobs j ON j.company_id = c.id AND j.status = "open"
     GROUP BY c.id, c.company_name
     ORDER BY open_roles DESC, vacancies DESC
     LIMIT 6'
)->fetchAll();

// ---- Deadlines worth acting on this fortnight ----
$closingSoon = $pdo->query(
    'SELECT j.id, j.title, j.deadline, j.job_type, c.company_name
     FROM jobs j
     INNER JOIN companies c ON j.company_id = c.id
     WHERE j.status = "open" AND j.deadline IS NOT NULL
       AND j.deadline BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
     ORDER BY j.deadline ASC
     LIMIT 6'
)->fetchAll();

// ---- Readiness checklist, personalised to the signed-in user ----
$checklist = [];
$checklistTitle = 'Get application-ready';
$checklistNote = '';
$checklistCta = ['label' => 'Create a student account', 'href' => 'register-student.php'];

if (is_logged_in() && current_user_role() === 'student') {
    $userId = (int) current_user_id();

    $stmt = $pdo->prepare('SELECT education, skills, bio, resume_path FROM student_profiles WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $profile = $stmt->fetch() ?: [];

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE student_id = :uid AND status <> "withdrawn"');
    $stmt->execute([':uid' => $userId]);
    $myApplications = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM saved_jobs WHERE student_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $mySaved = (int) $stmt->fetchColumn();

    $checklistTitle = 'Your readiness checklist';
    $checklistNote = 'Recruiters see your profile the moment you apply — finish these and your applications carry more weight.';
    $checklistCta = ['label' => 'Open my profile', 'href' => 'profile.php'];
    $checklist = [
        ['done' => !empty($profile['resume_path']), 'text' => 'Upload your resume (required before you can apply)'],
        ['done' => !empty($profile['skills']),      'text' => 'List your skills so you match the roles above'],
        ['done' => !empty($profile['education']),   'text' => 'Add your education history'],
        ['done' => !empty($profile['bio']),         'text' => 'Write a short bio introducing yourself'],
        ['done' => $mySaved > 0,                    'text' => 'Save jobs to your wishlist (' . $mySaved . ' saved)'],
        ['done' => $myApplications > 0,             'text' => 'Send your first application (' . $myApplications . ' sent)'],
    ];
} elseif (is_logged_in() && current_user_role() === 'recruiter') {
    $userId = (int) current_user_id();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM jobs j INNER JOIN companies c ON j.company_id = c.id
         WHERE c.user_id = :uid AND j.status = "open"'
    );
    $stmt->execute([':uid' => $userId]);
    $myOpenJobs = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM applications a
         INNER JOIN jobs j ON a.job_id = j.id
         INNER JOIN companies c ON j.company_id = c.id
         WHERE c.user_id = :uid AND a.status IN ("applied", "under_review")'
    );
    $stmt->execute([':uid' => $userId]);
    $pendingReview = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT description, website, location FROM companies WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $company = $stmt->fetch() ?: [];

    $checklistTitle = 'Your hiring checklist';
    $checklistNote = 'Candidates browse the Companies tab before applying — a complete profile gets more applications.';
    $checklistCta = ['label' => 'Open my dashboard', 'href' => 'recruiter-dashboard.php'];
    $checklist = [
        ['done' => !empty($company['description']), 'text' => 'Describe your company on your profile'],
        ['done' => !empty($company['location']),    'text' => 'Add your location so candidates can filter for you'],
        ['done' => !empty($company['website']),     'text' => 'Link your website'],
        ['done' => $myOpenJobs > 0,                 'text' => 'Keep at least one role open (' . $myOpenJobs . ' open)'],
        ['done' => $pendingReview === 0,            'text' => 'Clear your review queue (' . $pendingReview . ' waiting)'],
    ];
} else {
    $checklistNote = 'Create a free student account to apply, save jobs and track every application in one pipeline.';
    $checklist = [
        ['done' => false, 'text' => 'Create your student account'],
        ['done' => false, 'text' => 'Upload your resume'],
        ['done' => false, 'text' => 'List your skills and education'],
        ['done' => false, 'text' => 'Apply to the roles that match'],
    ];
}

$checklistDone = count(array_filter($checklist, static fn(array $item): bool => $item['done']));
$checklistPercent = $checklist ? (int) round($checklistDone / count($checklist) * 100) : 0;

// ---- Guides: short evergreen advice, each pointing back into live listings ----
$topSkillLabel = $topSkills ? $skillLabels[array_key_first($topSkills)] : '';
$guides = [
    [
        'icon' => 'description',
        'title' => 'Write a resume recruiters finish reading',
        'text' => 'Lead with outcomes, not duties. Mirror the exact wording of the skills listed in the posting — that is what recruiters scan for first.',
        'link' => ['label' => 'See what skills are being asked for', 'href' => '#skills'],
    ],
    [
        'icon' => 'target',
        'title' => 'Apply narrow, not wide',
        'text' => 'Ten tailored applications beat a hundred generic ones. Filter to the roles that match your stack, then write one paragraph on why you fit.',
        'link' => $topSkillLabel !== ''
            ? ['label' => 'Jobs asking for ' . $topSkillLabel, 'href' => 'jobs.php?title=' . rawurlencode($topSkillLabel)]
            : ['label' => 'Browse all jobs', 'href' => 'jobs.php'],
    ],
    [
        'icon' => 'schedule',
        'title' => 'Beat the deadline, not the crowd',
        'text' => 'Applications sent in the first days of a posting get read while the shortlist is still open. Sort by newest and move early.',
        'link' => ['label' => 'Roles closing soon', 'href' => '#deadlines'],
    ],
    [
        'icon' => 'record_voice_over',
        'title' => 'Prepare for the interview you will actually get',
        'text' => 'Re-read the posting the night before: the requirements section is usually the interview agenda in disguise.',
        'link' => ['label' => 'Companies hiring now', 'href' => 'companies.php'],
    ],
];

$pageTitle = 'Career Resources | CareerStudio';
$activeNav = 'resources';
require __DIR__ . '/site-nav.php';
?>

<main>
  <section class="container page-hero">
    <span class="eyebrow">
      <span class="material-symbols-outlined" style="font-size: 14px;">insights</span> Career hub
    </span>
    <h1 class="display-lg">Resources built from live hiring data</h1>
    <p class="body-lg text-variant">No generic advice lists. Every number below is calculated from the jobs recruiters
      have posted on CareerStudio, so it tells you what is being hired for right now.</p>

    <div class="stat-strip">
      <div class="stat-box">
        <div class="stat-value" data-live="open_jobs"><?= $openJobs ?></div>
        <div class="label-sm text-variant">Open roles</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" data-live="posted_week"><?= $postedWeek ?></div>
        <div class="label-sm text-variant">Posted this week</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" data-live="closing_soon"><?= $closingSoonCount ?></div>
        <div class="label-sm text-variant">Closing in 7 days</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" data-live="students"><?= $studentCount ?></div>
        <div class="label-sm text-variant">Students competing</div>
      </div>
    </div>
    <div style="margin-top: 12px;">
      <span class="live-pill"><span class="live-dot"></span> Live &middot; updated <span id="live-updated"><?= date('g:i:s A') ?></span></span>
    </div>
  </section>

  <!-- Skills in demand + where the jobs are -->
  <section class="container" id="skills" style="padding-top: 24px;">
    <div class="card-grid card-grid-2">
      <div class="entity-card">
        <div>
          <h2 class="headline-md">Skills in demand</h2>
          <p class="body-sm text-variant" style="margin-top: 4px;">Counted across every open posting. Click one to see
            the jobs asking for it.</p>
        </div>
        <?php if (!$topSkills): ?>
          <p class="body-sm text-variant">No skills listed on the open jobs yet.</p>
        <?php else: ?>
          <div>
            <?php foreach ($topSkills as $key => $count): ?>
              <a class="meter-row" href="jobs.php?title=<?= e(rawurlencode($skillLabels[$key])) ?>">
                <span class="meter-name"><?= e($skillLabels[$key]) ?></span>
                <span class="meter-track">
                  <span class="meter-fill" style="width: <?= (int) round($count / $maxSkill * 100) ?>%;"></span>
                </span>
                <span class="meter-count"><?= $count ?> job<?= $count === 1 ? '' : 's' ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="entity-card">
        <div>
          <h2 class="headline-md">Where the jobs are</h2>
          <p class="body-sm text-variant" style="margin-top: 4px;">Top locations on the board, plus the shape of the
            market right now.</p>
        </div>

        <?php if ($topLocations): ?>
          <div class="chip-row">
            <?php foreach ($topLocations as $row): ?>
              <a class="chip" href="jobs.php?location=<?= e(rawurlencode((string) $row['location'])) ?>">
                <?= e($row['location']) ?> &middot; <?= (int) $row['total'] ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="body-sm text-variant">No locations recorded on the open jobs yet.</p>
        <?php endif; ?>

        <div>
          <div class="mini-stat-label" style="margin-bottom: 8px;">By job type</div>
          <div class="chip-row">
            <?php foreach ($byType as $row): ?>
              <a class="chip chip-accent" href="jobs.php?job_type=<?= e(rawurlencode((string) $row['job_type'])) ?>">
                <?= e($row['job_type']) ?> &middot; <?= (int) $row['total'] ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <div>
          <div class="mini-stat-label" style="margin-bottom: 8px;">By work mode</div>
          <div class="chip-row">
            <?php foreach ($byMode as $row): ?>
              <a class="chip chip-green" href="<?= $row['work_mode'] === 'remote' ? 'remote.php' : 'jobs.php?work_mode=' . e(rawurlencode((string) $row['work_mode'])) ?>">
                <?= e($row['work_mode']) ?> &middot; <?= (int) $row['total'] ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Checklist + deadlines -->
  <section class="container" id="deadlines" style="padding-top: 24px;">
    <div class="card-grid card-grid-2">
      <div class="entity-card">
        <div>
          <h2 class="headline-md"><?= e($checklistTitle) ?></h2>
          <p class="body-sm text-variant" style="margin-top: 4px;"><?= e($checklistNote) ?></p>
        </div>

        <div>
          <div class="meter-row" style="padding-top: 0;">
            <span class="meter-name"><?= $checklistPercent ?>% complete</span>
            <span class="meter-track">
              <span class="meter-fill" style="width: <?= $checklistPercent ?>%;"></span>
            </span>
            <span class="meter-count"><?= $checklistDone ?>/<?= count($checklist) ?></span>
          </div>

          <?php foreach ($checklist as $item): ?>
            <div class="check-row <?= $item['done'] ? 'check-done' : 'check-todo' ?>">
              <span class="check-mark"><?= $item['done'] ? '&#10003;' : '&#9675;' ?></span>
              <span class="body-sm<?= $item['done'] ? ' text-variant' : '' ?>"><?= e($item['text']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="entity-foot">
          <span class="label-sm text-variant">Updates as soon as you save changes</span>
          <a class="btn btn-sm btn-primary" href="<?= e($checklistCta['href']) ?>"><?= e($checklistCta['label']) ?></a>
        </div>
      </div>

      <div class="entity-card">
        <div>
          <h2 class="headline-md">Closing in the next 14 days</h2>
          <p class="body-sm text-variant" style="margin-top: 4px;">Deadlines set by recruiters. Apply before these
            disappear.</p>
        </div>

        <?php if (!$closingSoon): ?>
          <p class="body-sm text-variant">Nothing closing soon — a good week to apply without rushing.</p>
        <?php else: ?>
          <div class="opening-list">
            <?php foreach ($closingSoon as $job):
                $daysLeft = (int) floor((strtotime($job['deadline']) - strtotime(date('Y-m-d'))) / 86400);
            ?>
              <a class="opening-row" href="jobs.php?id=<?= (int) $job['id'] ?>">
                <span>
                  <span class="opening-title"><?= e($job['title']) ?></span><br>
                  <span class="opening-meta"><?= e($job['company_name']) ?> &middot; <?= e($job['job_type']) ?></span>
                </span>
                <span class="chip <?= $daysLeft <= 3 ? 'chip-accent' : '' ?>">
                  <?= $daysLeft <= 0 ? 'Today' : $daysLeft . 'd left' ?>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="entity-foot">
          <span class="label-sm text-variant"><?= $closingSoonCount ?> closing within a week</span>
          <a class="btn btn-sm btn-outline" href="jobs.php">Browse all jobs</a>
        </div>
      </div>
    </div>
  </section>

  <!-- Top hiring companies -->
  <?php if ($topCompanies): ?>
    <section class="container" style="padding-top: 40px;">
      <div class="section-title">
        <div>
          <h2 class="headline-md">Who is hiring the most</h2>
          <p class="body-sm text-variant">Ranked by open roles on the board today.</p>
        </div>
        <a class="label-md view-all" href="companies.php">
          All companies <span class="material-symbols-outlined" style="font-size: 16px;">arrow_forward</span>
        </a>
      </div>

      <div class="card-grid">
        <?php foreach ($topCompanies as $company): ?>
          <a class="entity-card hover-lift" href="companies.php?id=<?= (int) $company['id'] ?>">
            <div class="entity-head">
              <div class="entity-logo" style="background: <?= e(avatar_color($company['company_name'])) ?>;">
                <?= e(initials($company['company_name'])) ?>
              </div>
              <div>
                <h3 class="entity-title"><?= e($company['company_name']) ?></h3>
                <div class="entity-sub">
                  <?= (int) $company['open_roles'] ?> open role<?= (int) $company['open_roles'] === 1 ? '' : 's' ?>
                  &middot; <?= (int) $company['vacancies'] ?> vacancies
                </div>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- Evergreen guides -->
  <section class="container" id="guides" style="padding-top: 40px; padding-bottom: 64px;">
    <div class="section-title">
      <div>
        <h2 class="headline-md">Playbooks</h2>
        <p class="body-sm text-variant">Short, practical, and wired into the live listings.</p>
      </div>
    </div>

    <div class="card-grid">
      <?php foreach ($guides as $guide): ?>
        <div class="entity-card hover-lift">
          <div class="entity-head">
            <div class="entity-logo" style="background: var(--dark-btn);">
              <span class="material-symbols-outlined"><?= e($guide['icon']) ?></span>
            </div>
            <h3 class="entity-title"><?= e($guide['title']) ?></h3>
          </div>
          <p class="body-sm text-variant" style="flex: 1;"><?= e($guide['text']) ?></p>
          <div class="entity-foot">
            <a class="label-md text-secondary" href="<?= e($guide['link']['href']) ?>">
              <?= e($guide['link']['label']) ?> &rarr;
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</main>

<script src="live-stats.js" defer></script>
<?php require __DIR__ . '/site-footer.php'; ?>
