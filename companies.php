<?php
/**
 * companies.php
 * Public company directory, built live from the database.
 *
 *   GET             -> searchable, sortable, paginated grid of companies with
 *                      their open-opening counts
 *   GET ?id=123     -> one company plus every opening it currently has
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pdo = get_db();

// ---------------------------------------------------------------
// GET ?id=123 : single company + all of its open roles
// ---------------------------------------------------------------
if (isset($_GET['id'])) {
    $companyId = (int) $_GET['id'];

    $stmt = $pdo->prepare(
        'SELECT c.*,
                (SELECT COUNT(*) FROM jobs j WHERE j.company_id = c.id AND j.status = "open") AS open_roles,
                (SELECT COALESCE(SUM(j.vacancy_count), 0) FROM jobs j WHERE j.company_id = c.id AND j.status = "open") AS vacancies
         FROM companies c
         WHERE c.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $companyId]);
    $company = $stmt->fetch();

    if (!$company) {
        flash('Company not found.', 'error');
        redirect('companies.php');
    }

    $stmt = $pdo->prepare(
        'SELECT id, title, job_type, work_mode, location, salary, vacancy_count, deadline, created_at
         FROM jobs
         WHERE company_id = :id AND status = "open"
         ORDER BY created_at DESC'
    );
    $stmt->execute([':id' => $companyId]);
    $openings = $stmt->fetchAll();

    $pageTitle = $company['company_name'] . ' | CareerStudio';
    $activeNav = 'companies';
    require __DIR__ . '/site-nav.php';
    ?>

    <main>
      <section class="container page-hero">
        <a class="label-md text-secondary" href="companies.php">&larr; All companies</a>

        <div class="entity-head" style="margin-top: 20px;">
          <div class="entity-logo" style="width: 64px; height: 64px; font-size: 26px; background: <?= e(avatar_color($company['company_name'])) ?>;">
            <?= e(initials($company['company_name'])) ?>
          </div>
          <div>
            <h1 class="headline-lg"><?= e($company['company_name']) ?></h1>
            <div class="entity-sub">
              <span class="material-symbols-outlined" style="font-size: 16px;">location_on</span>
              <?= $company['location'] ? e($company['location']) : 'Location not specified' ?>
            </div>
            <?php $site = safe_url($company['website']); ?>
            <?php if ($site): ?>
              <a class="body-sm text-secondary" href="<?= e($site) ?>" target="_blank" rel="noopener noreferrer">
                <?= e($company['website']) ?>
              </a>
            <?php endif; ?>
          </div>
        </div>

        <p class="body-md text-variant" style="margin-top: 20px; max-width: 720px;">
          <?= e($company['description'] ?: 'This company has not added a description yet.') ?>
        </p>

        <div class="chip-row" style="margin-top: 20px;">
          <span class="chip chip-accent"><?= (int) $company['open_roles'] ?> open role<?= (int) $company['open_roles'] === 1 ? '' : 's' ?></span>
          <span class="chip"><?= (int) $company['vacancies'] ?> total vacancies</span>
        </div>
      </section>

      <section class="container" style="padding-bottom: 64px;">
        <div class="section-title">
          <h2 class="headline-md">Current openings</h2>
        </div>

        <?php if (!$openings): ?>
          <div class="empty-panel">
            <p class="body-md">This company has no open positions right now.</p>
            <a class="btn btn-outline" style="margin-top: 16px;" href="jobs.php">Browse all jobs</a>
          </div>
        <?php else: ?>
          <div class="card-grid card-grid-2">
            <?php foreach ($openings as $job): ?>
              <div class="entity-card hover-lift">
                <div class="entity-body">
                  <h3 class="entity-title">
                    <a href="jobs.php?id=<?= (int) $job['id'] ?>"><?= e($job['title']) ?></a>
                  </h3>
                  <div class="entity-sub">
                    <span class="material-symbols-outlined" style="font-size: 16px;">location_on</span>
                    <?= $job['location'] ? e($job['location']) : 'Remote' ?>
                    &middot; posted <?= e(format_date($job['created_at'])) ?>
                  </div>
                  <div class="chip-row" style="margin-top: 12px;">
                    <span class="chip chip-green"><?= e($job['work_mode']) ?></span>
                    <span class="chip"><?= e($job['job_type']) ?></span>
                    <?php if ($job['salary']): ?><span class="chip"><?= e($job['salary']) ?></span><?php endif; ?>
                    <span class="chip"><?= (int) $job['vacancy_count'] ?> opening<?= (int) $job['vacancy_count'] === 1 ? '' : 's' ?></span>
                  </div>
                </div>
                <div class="entity-foot">
                  <span class="label-sm text-variant">
                    <?= $job['deadline'] ? 'Apply by ' . e(format_date($job['deadline'])) : 'No deadline' ?>
                  </span>
                  <a class="btn btn-sm btn-primary" href="jobs.php?id=<?= (int) $job['id'] ?>">View &amp; Apply</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>

    <?php
    require __DIR__ . '/site-footer.php';
    exit;
}

// ---------------------------------------------------------------
// GET : company directory
// ---------------------------------------------------------------
$search = query('q');
$location = query('location');
$sort = query('sort');
$hiringOnly = isset($_GET['hiring']) && $_GET['hiring'] === '1';

$where = ['1 = 1'];
$params = [];

if ($search !== '') {
    $where[] = 'c.company_name LIKE :search';
    $params[':search'] = '%' . $search . '%';
}
if ($location !== '') {
    $where[] = 'c.location LIKE :location';
    $params[':location'] = '%' . $location . '%';
}

// Only count jobs that are actually open — that is what "openings" means here.
$having = $hiringOnly ? 'HAVING open_roles > 0' : '';

$sortOptions = [
    'openings' => 'open_roles DESC, c.company_name ASC',
    // NULL last_posted (never posted) sorts last under DESC, which is what we want.
    'newest'   => 'last_posted DESC, c.company_name ASC',
    'name'     => 'c.company_name ASC',
];
if (!isset($sortOptions[$sort])) {
    $sort = 'openings';
}
$orderBy = $sortOptions[$sort];

$whereSql = implode(' AND ', $where);
[$page, $limit, $offset] = get_pagination($_GET, 9, 30);

$sql = "SELECT c.id, c.company_name, c.description, c.website, c.location,
               COUNT(j.id) AS open_roles,
               COALESCE(SUM(j.vacancy_count), 0) AS vacancies,
               COALESCE(SUM(j.job_type = 'internship'), 0) AS internships,
               COALESCE(SUM(j.work_mode = 'remote'), 0) AS remote_roles,
               MAX(j.created_at) AS last_posted
        FROM companies c
        LEFT JOIN jobs j ON j.company_id = c.id AND j.status = 'open'
        WHERE $whereSql
        GROUP BY c.id, c.company_name, c.description, c.website, c.location
        $having
        ORDER BY $orderBy
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$companies = $stmt->fetchAll();

// Total matching companies (same filters, minus paging) for the pager.
$countSql = "SELECT COUNT(*) FROM (
                SELECT c.id, COUNT(j.id) AS open_roles
                FROM companies c
                LEFT JOIN jobs j ON j.company_id = c.id AND j.status = 'open'
                WHERE $whereSql
                GROUP BY c.id
                $having
             ) AS matched";
$stmt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->execute();
$total = (int) $stmt->fetchColumn();
$totalPages = (int) max(1, ceil($total / $limit));

// Newest three openings per company on this page, fetched in one round trip.
$openingsByCompany = [];
if ($companies) {
    $ids = array_map('intval', array_column($companies, 'id'));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, company_id, title, job_type, work_mode, location, vacancy_count
         FROM jobs
         WHERE status = 'open' AND company_id IN ($placeholders)
         ORDER BY created_at DESC"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $job) {
        $openingsByCompany[(int) $job['company_id']][] = $job;
    }
}

$pageTitle = 'Companies Hiring | CareerStudio';
$activeNav = 'companies';
require __DIR__ . '/site-nav.php';
?>

<main>
  <section class="container page-hero">
    <span class="eyebrow">
      <span class="material-symbols-outlined" style="font-size: 14px;">apartment</span> Company directory
    </span>
    <h1 class="display-lg">Companies hiring right now</h1>
    <p class="body-lg text-variant">Every company below is pulled live from the portal. Open a card to see the exact
      roles they are recruiting for today.</p>

    <div class="stat-strip">
      <div class="stat-box">
        <div class="stat-value" data-live="companies"><?= (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() ?></div>
        <div class="label-sm text-variant">Registered companies</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" data-live="hiring"><?= (int) $pdo->query('SELECT COUNT(DISTINCT company_id) FROM jobs WHERE status = "open"')->fetchColumn() ?></div>
        <div class="label-sm text-variant">Actively hiring</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" data-live="open_jobs"><?= (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open"')->fetchColumn() ?></div>
        <div class="label-sm text-variant">Open roles</div>
      </div>
      <div class="stat-box">
        <div class="stat-value" data-live="vacancies"><?= (int) $pdo->query('SELECT COALESCE(SUM(vacancy_count), 0) FROM jobs WHERE status = "open"')->fetchColumn() ?></div>
        <div class="label-sm text-variant">Total vacancies</div>
      </div>
    </div>
    <div style="margin-top: 12px;">
      <span class="live-pill"><span class="live-dot"></span> Live &middot; updated <span id="live-updated"><?= date('g:i:s A') ?></span></span>
    </div>
  </section>

  <section class="container" style="padding-bottom: 64px;">
    <form class="filter-bar" method="get" action="companies.php">
      <input type="text" name="q" placeholder="Search company name" value="<?= e($search) ?>">
      <input type="text" name="location" placeholder="Location" value="<?= e($location) ?>">
      <select name="sort">
        <option value="openings"<?= $sort === 'openings' ? ' selected' : '' ?>>Most openings</option>
        <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>Recently posted</option>
        <option value="name"<?= $sort === 'name' ? ' selected' : '' ?>>Company name (A–Z)</option>
      </select>
      <button type="submit" class="btn btn-primary">Search</button>
      <label class="label-sm text-variant" style="display: flex; align-items: center; gap: 8px; grid-column: 1 / -1;">
        <input type="checkbox" name="hiring" value="1" style="width: auto;"<?= $hiringOnly ? ' checked' : '' ?>>
        Show only companies with open positions
      </label>
    </form>

    <div class="section-title">
      <h2 class="headline-md"><?= $total ?> compan<?= $total === 1 ? 'y' : 'ies' ?> found</h2>
      <span class="label-sm text-variant">Page <?= $page ?> of <?= $totalPages ?></span>
    </div>

    <?php if (!$companies): ?>
      <div class="empty-panel">
        <p class="body-md">No companies match your search yet.</p>
        <a class="btn btn-outline" style="margin-top: 16px;" href="companies.php">Clear filters</a>
      </div>
    <?php else: ?>
      <div class="card-grid">
        <?php foreach ($companies as $company):
            $openRoles = (int) $company['open_roles'];
            $openings = $openingsByCompany[(int) $company['id']] ?? [];
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
              <p class="entity-desc">
                <?= e($company['description'] ?: 'No company description added yet.') ?>
              </p>

              <div class="mini-stats" style="margin-top: 16px;">
                <div>
                  <div class="mini-stat-value"><?= $openRoles ?></div>
                  <div class="mini-stat-label">Open roles</div>
                </div>
                <div>
                  <div class="mini-stat-value"><?= (int) $company['vacancies'] ?></div>
                  <div class="mini-stat-label">Vacancies</div>
                </div>
                <div>
                  <div class="mini-stat-value"><?= (int) $company['internships'] ?></div>
                  <div class="mini-stat-label">Internships</div>
                </div>
              </div>

              <?php if ($openings): ?>
                <div class="opening-list" style="margin-top: 16px;">
                  <?php foreach (array_slice($openings, 0, 3) as $job): ?>
                    <a class="opening-row" href="jobs.php?id=<?= (int) $job['id'] ?>">
                      <span>
                        <span class="opening-title"><?= e($job['title']) ?></span><br>
                        <span class="opening-meta">
                          <?= e($job['job_type']) ?> &middot; <?= e($job['work_mode']) ?>
                          <?= $job['location'] ? ' &middot; ' . e($job['location']) : '' ?>
                        </span>
                      </span>
                      <span class="material-symbols-outlined text-variant" style="font-size: 18px;">chevron_right</span>
                    </a>
                  <?php endforeach; ?>
                  <?php if ($openRoles > 3): ?>
                    <span class="opening-more">+ <?= $openRoles - 3 ?> more opening<?= $openRoles - 3 === 1 ? '' : 's' ?></span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <p class="label-sm text-variant" style="margin-top: 16px;">No open positions at the moment.</p>
              <?php endif; ?>
            </div>

            <div class="entity-foot">
              <span class="label-sm text-variant">
                <?= $company['last_posted'] ? 'Last posted ' . e(format_date($company['last_posted'])) : 'Nothing posted yet' ?>
              </span>
              <a class="btn btn-sm <?= $openRoles ? 'btn-primary' : 'btn-outline' ?>"
                 href="companies.php?id=<?= (int) $company['id'] ?>">
                <?= $openRoles ? 'View openings' : 'View profile' ?>
              </a>
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
