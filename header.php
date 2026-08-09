<?php
/**
 * header.php
 * Shared page top. Two layouts:
 *
 *   $layout = 'site'  (default) — public pages, horizontal navbar across the top
 *   $layout = 'app'             — signed-in pages: icon rail down the left plus
 *                                 a top app bar, then an open <main class="canvas">
 *
 * Set before including:
 *   $pageTitle    browser tab title
 *   $topbarTitle  heading shown in the app bar (defaults to $pageTitle)
 *   $activeNav    which rail item is highlighted
 *   $layout       'app' | 'site'
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$pageTitle = $pageTitle ?? APP_NAME;
$topbarTitle = $topbarTitle ?? $pageTitle;
$activeNav = $activeNav ?? '';
$layout = $layout ?? 'site';
$flashes = take_flashes();

/**
 * Inline SVG icons — keeps the rail dependency-free (no icon font, no external
 * request), so it renders identically offline.
 */
function nav_icon(string $name): string
{
    $paths = [
        'home'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V20h13V9.5"/><path d="M9.5 20v-6h5v6"/>',
        'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>'
                     . '<rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/>',
        'bookmark'  => '<path d="M6 4h12v17l-6-4.2L6 21z"/>',
        'group'     => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-5.6 6.5-5.6s6.5 2 6.5 5.6"/>'
                     . '<path d="M16.5 5.2a3.2 3.2 0 0 1 0 5.9"/><path d="M18 14.8c2.1.6 3.5 2.2 3.5 5.2"/>',
        'briefcase' => '<rect x="3" y="7.5" width="18" height="13" rx="2"/><path d="M8.5 7.5V5.5a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v2"/><path d="M3 12.5h18"/>',
        'person'    => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.9 3.1-6 7-6s7 2.1 7 6"/>',
        'bell'      => '<path d="M18 8.5a6 6 0 1 0-12 0c0 6-2 7.5-2 7.5h16s-2-1.5-2-7.5"/><path d="M13.7 20a2 2 0 0 1-3.4 0"/>',
        'logout'    => '<path d="M15 17l5-5-5-5"/><path d="M20 12H9"/><path d="M12 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6"/>',
        'export'    => '<path d="M12 15V3"/><path d="M8 7l4-4 4 4"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
        'add'       => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'layers'    => '<path d="M12 3 3 7.5l9 4.5 9-4.5z"/><path d="M3 12.5 12 17l9-4.5"/><path d="M3 17 12 21.5 21 17"/>',
    ];

    return '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
         . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Rail items for the signed-in role. */
function rail_items(): array
{
    if (current_user_role() === 'recruiter') {
        return [
            ['key' => 'dashboard',  'icon' => 'home',      'label' => 'Pipeline',    'href' => 'recruiter-dashboard.php'],
            ['key' => 'myjobs',     'icon' => 'briefcase', 'label' => 'My Jobs',     'href' => 'recruiter-dashboard.php?tab=jobs'],
            ['key' => 'applicants', 'icon' => 'group',     'label' => 'Applicants',  'href' => 'recruiter-dashboard.php?tab=applicants'],
            ['key' => 'jobs',       'icon' => 'search',    'label' => 'Browse Jobs', 'href' => 'jobs.php'],
            ['key' => 'profile',    'icon' => 'person',    'label' => 'My Profile',  'href' => 'profile.php'],
        ];
    }

    return [
        ['key' => 'dashboard', 'icon' => 'home',     'label' => 'Pipeline',    'href' => 'student-dashboard.php'],
        ['key' => 'jobs',      'icon' => 'search',   'label' => 'Browse Jobs', 'href' => 'jobs.php'],
        ['key' => 'saved',     'icon' => 'bookmark', 'label' => 'Saved Jobs',  'href' => 'jobs.php?saved=1'],
        ['key' => 'profile',   'icon' => 'person',   'label' => 'My Profile',  'href' => 'profile.php'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="<?= $layout === 'app' ? 'is-app' : 'is-site' ?>">

<?php if ($layout === 'app'):
    $notifications = notification_count(get_db());
    $notifyHref = current_user_role() === 'recruiter'
        ? 'recruiter-dashboard.php?tab=applicants&status=applied'
        : 'student-dashboard.php';
?>
  <!-- ============ Signed-in shell: full-width topbar + app body with sidebar ============ -->
  <div class="app-shell">

    <header class="topbar">
      <div class="topbar-left">
        <a href="<?= e(dashboard_url()) ?>" class="topbar-logo" aria-label="<?= e(APP_NAME) ?> home">
          <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1; font-size: 24px; color: #0f172a;">work</span>
          <span class="brand">CareerStudio</span>
        </a>
        <h1 class="topbar-title"><?= e($topbarTitle) ?></h1>
      </div>
      <div class="topbar-actions">
        <a href="<?= e($notifyHref) ?>" class="topbar-icon"
           aria-label="<?= $notifications ?> notifications" data-tip="<?= $notifications ?> to review">
          <?= nav_icon('bell') ?>
          <?php if ($notifications > 0): ?>
            <span class="topbar-dot"><?= $notifications > 9 ? '9+' : $notifications ?></span>
          <?php endif; ?>
        </a>
        <span class="topbar-sep"></span>
        <a href="profile.php" class="topbar-user" data-tip="My Profile">
          <span class="topbar-avatar"><?= e(initials(current_user_name())) ?></span>
          <span class="topbar-name"><?= e(current_user_name()) ?></span>
        </a>
      </div>
    </header>

    <div class="app-body">
      <aside class="rail">
        <nav class="rail-nav">
          <?php foreach (rail_items() as $item): ?>
            <a href="<?= e($item['href']) ?>"
               class="rail-btn<?= $activeNav === $item['key'] ? ' active' : '' ?>"
               aria-label="<?= e($item['label']) ?>"
               data-tip="<?= e($item['label']) ?>">
              <?= nav_icon($item['icon']) ?>
            </a>
          <?php endforeach; ?>
        </nav>

        <div class="rail-foot">
          <form method="post" action="logout.php">
            <?= csrf_field() ?>
            <button type="submit" class="rail-btn rail-logout" aria-label="Logout" data-tip="Logout">
              <?= nav_icon('logout') ?>
            </button>
          </form>
        </div>
      </aside>

      <div class="app-main">

<?php else: ?>
  <!-- ============ Public shell: horizontal navbar ============ -->
  <nav class="navbar">
    <div class="navbar-inner">
      <a href="index.php" class="brand" style="display: flex; align-items: center; gap: 8px;">
        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1; font-size: 24px;">work</span>
        CareerStudio
      </a>
      <div class="nav-links">
        <a href="jobs.php"<?= $activeNav === 'jobs' ? ' class="active"' : '' ?>>Browse Jobs</a>
        <?php if (is_logged_in()): ?>
          <a href="<?= e(dashboard_url()) ?>"<?= $activeNav === 'dashboard' ? ' class="active"' : '' ?>>Dashboard</a>
          <a href="profile.php"<?= $activeNav === 'profile' ? ' class="active"' : '' ?>>Profile</a>
          <span class="nav-user"><?= e(current_user_name()) ?></span>
          <form method="post" action="logout.php" class="nav-form">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary btn-sm">Logout</button>
          </form>
        <?php else: ?>
          <a href="login.php"<?= $activeNav === 'login' ? ' class="active"' : '' ?>>Login</a>
          <a href="register-student.php" class="btn btn-primary btn-sm">Get Started</a>
        <?php endif; ?>
      </div>
    </div>
  </nav>
<?php endif; ?>

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
