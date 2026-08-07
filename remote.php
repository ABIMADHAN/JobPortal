<?php
/**
 * remote.php
 * Remote work hub: every open role a recruiter marked work_mode = "remote"
 * (optionally including hybrid), plus a live snapshot of the remote market.
 *
 *   GET ?q= &job_type= &include_hybrid=1 &page=
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pdo = get_db();

$search = query('q');
$jobType = query('job_type');
if (!in_array($jobType, ['full-time', 'part-time', 'internship', 'contract'], true)) {
    $jobType = '';
}
$includeHybrid = isset($_GET['include_hybrid']) && $_GET['include_hybrid'] === '1';

$where = ['j.status = "open"'];
$params = [];

// The whole point of this page: work you can do from anywhere. Hybrid is opt-in.
$where[] = $includeHybrid ? 'j.work_mode IN ("remote", "hybrid")' : 'j.work_mode = "remote"';

if ($search !== '') {
    // Separate placeholders per occurrence — native prepares reject reuse.
    $where[] = '(j.title LIKE :search_title OR j.skills_required LIKE :search_skill OR c.company_name LIKE :search_company)';
    $params[':search_title'] = '%' . $search . '%';
    $params[':search_skill'] = '%' . $search . '%';
    $params[':search_company'] = '%' . $search . '%';
}
if ($jobType !== '') {
    $where[] = 'j.job_type = :job_type';
    $params[':job_type'] = $jobType;
}

$whereSql = implode(' AND ', $where);
[$page, $limit, $offset] = get_pagination($_GET, 9, 30);

$stmt = $pdo->prepare(
    "SELECT j.id, j.title, j.description, j.job_type, j.work_mode, j.location, j.salary,
            j.skills_required, j.vacancy_count, j.deadline, j.created_at,
            c.id AS company_id, c.company_name
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
    "SELECT COUNT(*) FROM jobs j INNER JOIN companies c ON j.company_id = c.id WHERE $whereSql"
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->execute();
$total = (int) $stmt->fetchColumn();
$totalPages = (int) max(1, ceil($total / $limit));

// Companies with the most remote roles — a quick way into the biggest employers.
$topRemote = $pdo->query(
    'SELECT c.id, c.company_name, COUNT(j.id) AS remote_roles
     FROM companies c
     INNER JOIN jobs j ON j.company_id = c.id AND j.status = "open" AND j.work_mode = "remote"
     GROUP BY c.id, c.company_name
     ORDER BY remote_roles DESC, c.company_name ASC
     LIMIT 8'
)->fetchAll();

// Remote roles broken down by contract type, for the "what kind of work" strip.
$byType = $pdo->query(
    'SELECT job_type, COUNT(*) AS total
     FROM jobs
     WHERE status = "open" AND work_mode = "remote"
     GROUP BY job_type
     ORDER BY total DESC'
)->fetchAll();

$remoteCount = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open" AND work_mode = "remote"')->fetchColumn();
$hybridCount = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open" AND work_mode = "hybrid"')->fetchColumn();
$remoteCompanies = (int) $pdo->query('SELECT COUNT(DISTINCT company_id) FROM jobs WHERE status = "open" AND work_mode = "remote"')->fetchColumn();
$remoteSeats = (int) $pdo->query('SELECT COALESCE(SUM(vacancy_count), 0) FROM jobs WHERE status = "open" AND work_mode = "remote"')->fetchColumn();

$pageTitle = 'Remote Jobs | CareerStudio';
$activeNav = 'remote';
require __DIR__ . '/site-nav.php';
?>

<main>
  <section class="container page-hero">
    <span class="eyebrow">
      <span class="material-symbols-outlined" style="font-size: 14px;">public</span> Work from anywhere
    </span>
    <h1 class="display-lg">Remote roles, updated live</h1>
    <p class="body-lg text-variant">Every opening a recruiter published as remote, in one place. Flip on hybrid if you
      are happy to be in the office a couple of days a week.</p>

    <div class="stat-strip">
      <div class="stat-box">
        <div class="stat-value" data-live="remote"><?= $remoteCount ?></div>
        <div class="label-sm text-variant">Remote roles</div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?= $remoteCompanies ?></div>
        <div class="label-sm text-variant">Companies hiring remotely</div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?= $remoteSeats ?></div>
        <div class="label-sm text-variant">Remote vacancies</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" data-live="hybrid"><?= $hybridCount ?></div>
        <div class="label-sm text-variant">Hybrid roles</div>
      </div>
    </div>
    <div style="margin-top: 12px;">
      <span class="live-pill"><span class="live-dot"></span> Live &middot; updated <span id="live-updated"><?= date('g:i:s A') ?></span></span>
    </div>
  </section>

  <?php if ($byType || $topRemote): ?>
    <section class="container" style="padding-bottom: 8px;">
      <div class="card-grid card-grid-2">
        <?php if ($byType): ?>
          <div class="entity-card">
            <h3 class="entity-title">Remote work by type</h3>
            <div class="chip-row">
              <?php foreach ($byType as $row): ?>
                <a class="chip chip-accent" href="remote.php?job_type=<?= e(rawurlencode($row['job_type'])) ?>">
                  <?= e($row['job_type']) ?> &middot; <?= (int) $row['total'] ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($topRemote): ?>
          <div class="entity-card">
            <h3 class="entity-title">Top remote employers</h3>
            <div class="chip-row">
              <?php foreach ($topRemote as $row): ?>
                <a class="chip" href="companies.php?id=<?= (int) $row['id'] ?>">
                  <?= e($row['company_name']) ?> &middot; <?= (int) $row['remote_roles'] ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <section class="container section-padding" style="padding-top: 32px; padding-bottom: 64px;">
    <form class="filter-bar" method="get" action="remote.php">
      <input type="text" name="q" placeholder="Role, skill or company" value="<?= e($search) ?>">
      <select name="job_type">
        <option value="">All job types</option>
        <?php foreach (['full-time' => 'Full-time', 'part-time' => 'Part-time', 'internship' => 'Internship', 'contract' => 'Contract'] as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= $jobType === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="label-sm text-variant" style="display: flex; align-items: center; gap: 8px;">
        <input type="checkbox" name="include_hybrid" value="1" style="width: auto;"<?= $includeHybrid ? ' checked' : '' ?>>
        Include hybrid
      </label>
      <button type="submit" class="btn btn-primary">Search</button>
    </form>

    <div class="section-title">
      <h2 class="headline-md"><?= $total ?> <?= $includeHybrid ? 'remote &amp; hybrid' : 'remote' ?> role<?= $total === 1 ? '' : 's' ?></h2>
      <span class="label-sm text-variant">Page <?= $page ?> of <?= $totalPages ?></span>
    </div>

    <?php if (!$jobs): ?>
      <div class="empty-panel">
        <p class="body-md">No remote roles match your filters yet.</p>
        <a class="btn btn-outline" style="margin-top: 16px;" href="remote.php?include_hybrid=1">Try including hybrid roles</a>
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
                </div>
              </div>
            </div>

            <div class="entity-body">
              <p class="entity-desc"><?= e($job['description']) ?></p>
              <div class="chip-row" style="margin-top: 12px;">
                <span class="chip chip-green"><?= e($job['work_mode']) ?></span>
                <span class="chip"><?= e($job['job_type']) ?></span>
                <?php if ($job['salary']): ?><span class="chip"><?= e($job['salary']) ?></span><?php endif; ?>
                <?php if ($job['location']): ?><span class="chip"><?= e($job['location']) ?> friendly</span><?php endif; ?>
                <span class="chip"><?= (int) $job['vacancy_count'] ?> opening<?= (int) $job['vacancy_count'] === 1 ? '' : 's' ?></span>
              </div>
            </div>

            <div class="entity-foot">
              <span class="label-sm text-variant">
                <?= $job['deadline'] ? 'Apply by ' . e(format_date($job['deadline'])) : 'Posted ' . e(format_date($job['created_at'])) ?>
              </span>
              <a class="btn btn-sm btn-success" href="jobs.php?id=<?= (int) $job['id'] ?>">View &amp; Apply</a>
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
