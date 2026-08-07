<?php
/**
 * internships.php
 * Live internship board grouped by company: every company that currently has at
 * least one open job of type "internship", together with the exact roles.
 *
 *   GET ?q= &location= &work_mode= &page=
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pdo = get_db();

$search = query('q');
$location = query('location');
$workMode = query('work_mode');
if (!in_array($workMode, ['remote', 'hybrid', 'onsite'], true)) {
    $workMode = '';
}

// Conditions shared by the company roll-up and the per-company role list, so
// both always agree on what counts as a matching internship.
$jobWhere = ['j.status = "open"', 'j.job_type = "internship"'];
$params = [];

if ($search !== '') {
    // One placeholder per occurrence: native prepared statements reject a named
    // parameter that appears twice in the same statement.
    $jobWhere[] = '(j.title LIKE :search_title OR j.skills_required LIKE :search_skill OR c.company_name LIKE :search_company)';
    $params[':search_title'] = '%' . $search . '%';
    $params[':search_skill'] = '%' . $search . '%';
    $params[':search_company'] = '%' . $search . '%';
}
if ($location !== '') {
    $jobWhere[] = '(j.location LIKE :loc_job OR c.location LIKE :loc_company)';
    $params[':loc_job'] = '%' . $location . '%';
    $params[':loc_company'] = '%' . $location . '%';
}
if ($workMode !== '') {
    $jobWhere[] = 'j.work_mode = :work_mode';
    $params[':work_mode'] = $workMode;
}

$jobWhereSql = implode(' AND ', $jobWhere);
[$page, $limit, $offset] = get_pagination($_GET, 9, 30);

// ---- Companies offering internships ----
$stmt = $pdo->prepare(
    "SELECT c.id, c.company_name, c.description, c.location,
            COUNT(j.id) AS internship_roles,
            COALESCE(SUM(j.vacancy_count), 0) AS seats,
            COALESCE(SUM(j.work_mode = 'remote'), 0) AS remote_roles,
            MAX(j.created_at) AS last_posted
     FROM companies c
     INNER JOIN jobs j ON j.company_id = c.id
     WHERE $jobWhereSql
     GROUP BY c.id, c.company_name, c.description, c.location
     ORDER BY internship_roles DESC, last_posted DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$companies = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT c.id)
     FROM companies c
     INNER JOIN jobs j ON j.company_id = c.id
     WHERE $jobWhereSql"
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->execute();
$total = (int) $stmt->fetchColumn();
$totalPages = (int) max(1, ceil($total / $limit));

// ---- The matching internships themselves, for the cards ----
$rolesByCompany = [];
if ($companies) {
    // Ids come straight from the query above and are cast to int, so inlining
    // them here keeps named and positional placeholders from being mixed.
    $idList = implode(',', array_map('intval', array_column($companies, 'id')));
    $stmt = $pdo->prepare(
        "SELECT j.id, j.company_id, j.title, j.work_mode, j.location, j.salary,
                j.vacancy_count, j.deadline, j.created_at
         FROM jobs j
         INNER JOIN companies c ON j.company_id = c.id
         WHERE $jobWhereSql AND j.company_id IN ($idList)
         ORDER BY j.created_at DESC"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    foreach ($stmt->fetchAll() as $role) {
        $rolesByCompany[(int) $role['company_id']][] = $role;
    }
}

// ---- Headline counters ----
$internshipCount = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open" AND job_type = "internship"')->fetchColumn();
$internshipSeats = (int) $pdo->query('SELECT COALESCE(SUM(vacancy_count), 0) FROM jobs WHERE status = "open" AND job_type = "internship"')->fetchColumn();
$internshipCompanies = (int) $pdo->query('SELECT COUNT(DISTINCT company_id) FROM jobs WHERE status = "open" AND job_type = "internship"')->fetchColumn();
$remoteInternships = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open" AND job_type = "internship" AND work_mode = "remote"')->fetchColumn();

$pageTitle = 'Internships | CareerStudio';
$activeNav = 'internships';
require __DIR__ . '/site-nav.php';
?>

<main>
  <section class="container page-hero">
    <span class="eyebrow">
      <span class="material-symbols-outlined" style="font-size: 14px;">school</span> Internship board
    </span>
    <h1 class="display-lg">Internships open for applications</h1>
    <p class="body-lg text-variant">Grouped by company so you can see who is taking interns and exactly which roles are
      live. Everything here updates the moment a recruiter posts.</p>

    <div class="stat-strip">
      <div class="stat-box">
        <div class="stat-value" data-live="internships"><?= $internshipCount ?></div>
        <div class="label-sm text-variant">Open internships</div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?= $internshipSeats ?></div>
        <div class="label-sm text-variant">Seats available</div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?= $internshipCompanies ?></div>
        <div class="label-sm text-variant">Companies taking interns</div>
      </div>
      <div class="stat-box">
        <div class="stat-value"><?= $remoteInternships ?></div>
        <div class="label-sm text-variant">Remote internships</div>
      </div>
    </div>
    <div style="margin-top: 12px;">
      <span class="live-pill"><span class="live-dot"></span> Live &middot; updated <span id="live-updated"><?= date('g:i:s A') ?></span></span>
    </div>
  </section>

  <section class="container" style="padding-bottom: 64px;">
    <form class="filter-bar" method="get" action="internships.php">
      <input type="text" name="q" placeholder="Role, skill or company" value="<?= e($search) ?>">
      <input type="text" name="location" placeholder="Location" value="<?= e($location) ?>">
      <select name="work_mode">
        <option value="">Any work mode</option>
        <?php foreach (['remote' => 'Remote', 'hybrid' => 'Hybrid', 'onsite' => 'Onsite'] as $value => $label): ?>
          <option value="<?= e($value) ?>"<?= $workMode === $value ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">Search</button>
    </form>

    <div class="section-title">
      <h2 class="headline-md"><?= $total ?> compan<?= $total === 1 ? 'y' : 'ies' ?> hiring interns</h2>
      <a class="label-md view-all" href="jobs.php?job_type=internship">
        Open in job search <span class="material-symbols-outlined" style="font-size: 16px;">arrow_forward</span>
      </a>
    </div>

    <?php if (!$companies): ?>
      <div class="empty-panel">
        <p class="body-md">No internships match your filters right now.</p>
        <a class="btn btn-outline" style="margin-top: 16px;" href="internships.php">Clear filters</a>
      </div>
    <?php else: ?>
      <div class="card-grid">
        <?php foreach ($companies as $company):
            $roles = $rolesByCompany[(int) $company['id']] ?? [];
            $roleCount = (int) $company['internship_roles'];
        ?>
          <div class="entity-card hover-lift">
            <div class="entity-head">
              <div class="entity-logo" style="background: <?= e(avatar_color($company['company_name'])) ?>;">
                <?= e(initials($company['company_name'])) ?>
              </div>
              <div>
                <h3 class="entity-title">
                  <a href="companies.php?id=<?= (int) $company['id'] ?>"><?= e($company['company_name']) ?></a>
                </h3>
                <div class="entity-sub">
                  <span class="material-symbols-outlined" style="font-size: 16px;">location_on</span>
                  <?= $company['location'] ? e($company['location']) : 'Location not set' ?>
                </div>
              </div>
            </div>

            <div class="entity-body">
              <div class="chip-row">
                <span class="chip chip-accent"><?= $roleCount ?> internship<?= $roleCount === 1 ? '' : 's' ?></span>
                <span class="chip"><?= (int) $company['seats'] ?> seat<?= (int) $company['seats'] === 1 ? '' : 's' ?></span>
                <?php if ((int) $company['remote_roles'] > 0): ?>
                  <span class="chip chip-green"><?= (int) $company['remote_roles'] ?> remote</span>
                <?php endif; ?>
              </div>

              <div class="opening-list" style="margin-top: 16px;">
                <?php foreach (array_slice($roles, 0, 3) as $role): ?>
                  <a class="opening-row" href="jobs.php?id=<?= (int) $role['id'] ?>">
                    <span>
                      <span class="opening-title"><?= e($role['title']) ?></span><br>
                      <span class="opening-meta">
                        <?= e($role['work_mode']) ?>
                        <?= $role['location'] ? ' &middot; ' . e($role['location']) : '' ?>
                        <?= $role['salary'] ? ' &middot; ' . e($role['salary']) : '' ?>
                        &middot; <?= (int) $role['vacancy_count'] ?> opening<?= (int) $role['vacancy_count'] === 1 ? '' : 's' ?>
                      </span>
                    </span>
                    <span class="material-symbols-outlined text-variant" style="font-size: 18px;">chevron_right</span>
                  </a>
                <?php endforeach; ?>
                <?php if ($roleCount > 3): ?>
                  <span class="opening-more">+ <?= $roleCount - 3 ?> more internship<?= $roleCount - 3 === 1 ? '' : 's' ?></span>
                <?php endif; ?>
              </div>
            </div>

            <div class="entity-foot">
              <span class="label-sm text-variant">
                <?= $company['last_posted'] ? 'Posted ' . e(format_date($company['last_posted'])) : '' ?>
              </span>
              <a class="btn btn-sm btn-primary" href="companies.php?id=<?= (int) $company['id'] ?>">View company</a>
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
