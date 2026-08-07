<?php
/**
 * site-nav.php
 * Shared top of the public "CareerStudio" pages (index, companies, internships,
 * remote, resources). Emits everything from <!DOCTYPE html> down to </header>.
 *
 * Set before including:
 *   $pageTitle   browser tab title
 *   $activeNav   'jobs' | 'companies' | 'internships' | 'remote' | 'resources'
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pageTitle = $pageTitle ?? 'CareerStudio | Premium Job Portal';
$activeNav = $activeNav ?? '';
$flashes = take_flashes();

/** The one place the public navbar is defined — add a tab here and it appears everywhere. */
$navItems = [
    'jobs'        => ['label' => 'Jobs',        'href' => 'jobs.php'],
    'companies'   => ['label' => 'Companies',   'href' => 'companies.php'],
    'internships' => ['label' => 'Internships', 'href' => 'internships.php'],
    'remote'      => ['label' => 'Remote',      'href' => 'remote.php'],
    'resources'   => ['label' => 'Resources',   'href' => 'resources.php'],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <title><?= e($pageTitle) ?></title>
  <link
    href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap"
    rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="site.css">
</head>

<body>
  <!-- TopNavBar -->
  <header class="site-header">
    <div class="container header-inner">
      <!-- Brand -->
      <a class="brand" href="index.php">
        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1;">work</span>
        CareerStudio
      </a>
      <!-- Nav Links (Desktop) -->
      <nav class="nav-links label-md">
        <?php foreach ($navItems as $key => $item): ?>
          <a class="<?= $activeNav === $key ? 'active' : '' ?>" href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
        <?php endforeach; ?>
      </nav>
      <!-- Actions -->
      <div class="header-actions">
        <div class="header-icons">
          <a class="btn-icon" href="<?= is_logged_in() ? e(dashboard_url()) : 'login.php' ?>" aria-label="Notifications">
            <span class="material-symbols-outlined">notifications</span>
          </a>
          <a class="btn-icon" href="jobs.php" aria-label="Search jobs">
            <span class="material-symbols-outlined">search</span>
          </a>
          <a class="btn-icon" href="<?= is_logged_in() ? e(dashboard_url()) : 'login.php' ?>" aria-label="Account">
            <span class="material-symbols-outlined">login</span>
          </a>
        </div>
        <div class="header-btns">
          <?php if (is_logged_in()): ?>
            <a href="<?= e(dashboard_url()) ?>" class="btn btn-outline">Dashboard</a>
            <form method="post" action="logout.php">
              <?= csrf_field() ?>
              <button type="submit" class="btn btn-dark">Logout</button>
            </form>
          <?php else: ?>
            <a href="login.php" class="btn btn-outline">Login</a>
            <a href="register-recruiter.php" class="btn btn-dark">Post a Job</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </header>

<?php if ($flashes): ?>
  <div class="toast-container">
    <?php foreach ($flashes as $f): ?>
      <div class="toast toast-<?= e($f['type']) ?>">
        <span class="toast-icon"><?= $f['type'] === 'error' ? '&#9888;' : '&#10003;' ?></span>
        <div class="toast-body">
          <span class="toast-title"><?= $f['type'] === 'error' ? 'Something went wrong' : 'Success' ?></span>
          <?= e($f['message']) ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
