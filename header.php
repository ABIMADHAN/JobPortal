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
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V20h13V9.5"/><path d="M9.5 20v-6h5v6"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/>'
            . '<rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/>',
        'bookmark' => '<path d="M6 4h12v17l-6-4.2L6 21z"/>',
        'group' => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-5.6 6.5-5.6s6.5 2 6.5 5.6"/>'
            . '<path d="M16.5 5.2a3.2 3.2 0 0 1 0 5.9"/><path d="M18 14.8c2.1.6 3.5 2.2 3.5 5.2"/>',
        'briefcase' => '<rect x="3" y="7.5" width="18" height="13" rx="2"/><path d="M8.5 7.5V5.5a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v2"/><path d="M3 12.5h18"/>',
        'person' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.9 3.1-6 7-6s7 2.1 7 6"/>',
        'bell' => '<path d="M18 8.5a6 6 0 1 0-12 0c0 6-2 7.5-2 7.5h16s-2-1.5-2-7.5"/><path d="M13.7 20a2 2 0 0 1-3.4 0"/>',
        'logout' => '<path d="M15 17l5-5-5-5"/><path d="M20 12H9"/><path d="M12 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6"/>',
        'export' => '<path d="M12 15V3"/><path d="M8 7l4-4 4 4"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
        'add' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'layers' => '<path d="M12 3 3 7.5l9 4.5 9-4.5z"/><path d="M3 12.5 12 17l9-4.5"/><path d="M3 17 12 21.5 21 17"/>',
    ];

    return '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Rail items for the signed-in role. */
function rail_items(): array
{
    if (current_user_role() === 'recruiter') {
        return [
            ['key' => 'dashboard', 'icon' => 'home', 'label' => 'Pipeline', 'href' => 'recruiter-dashboard.php'],
            ['key' => 'myjobs', 'icon' => 'briefcase', 'label' => 'My Jobs', 'href' => 'recruiter-dashboard.php?tab=jobs'],
            ['key' => 'applicants', 'icon' => 'group', 'label' => 'Applicants', 'href' => 'recruiter-dashboard.php?tab=applicants'],
            ['key' => 'jobs', 'icon' => 'search', 'label' => 'Browse Jobs', 'href' => 'jobs.php'],
            ['key' => 'profile', 'icon' => 'person', 'label' => 'My Profile', 'href' => 'profile.php'],
        ];
    }

    return [
        ['key' => 'dashboard', 'icon' => 'home', 'label' => 'Pipeline', 'href' => 'student-dashboard.php'],
        ['key' => 'jobs', 'icon' => 'search', 'label' => 'Browse Jobs', 'href' => 'jobs.php'],
        ['key' => 'saved', 'icon' => 'bookmark', 'label' => 'Saved Jobs', 'href' => 'wishlist.php'],
        ['key' => 'profile', 'icon' => 'person', 'label' => 'My Profile', 'href' => 'profile.php'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="<?= $layout === 'app' ? 'is-app' : 'is-site' ?>">

    <?php if ($layout === 'app'):
        $userRole = current_user_role();
        $userName = current_user_name();
        $userInitials = initials($userName);
        $userAvatar = current_user_avatar();
        if ($userAvatar === null && is_logged_in()) {
            ensure_profile_image_column(get_db());
            $stmtImg = get_db()->prepare('SELECT profile_image FROM users WHERE id = :uid LIMIT 1');
            $stmtImg->execute([':uid' => current_user_id()]);
            $userAvatar = $stmtImg->fetchColumn() ?: null;
            $_SESSION['profile_image'] = $userAvatar;
        }
        $notifications = get_unread_notification_count(get_db());
        $notifyHref = 'notifications-page.php';

        $userTitle = $userRole === 'recruiter' ? 'Recruitment Lead' : 'Software Candidate';
        $workspaceTag = $userRole === 'recruiter' ? 'TVS • HIRING' : 'CAREER • CANDIDATE';
        $todayDateStr = date('l, d M Y');
        ?>
        <!-- Header TopBar -->
        <header class="topbar">
            <div class="header-left">
                <button class="menu-toggle-btn" title="Toggle Navigation" type="button"
                    onclick="document.body.classList.toggle('sidebar-collapsed')">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
                        </path>
                    </svg>
                </button>
                <a href="<?= e(dashboard_url()) ?>" class="brand" style="text-decoration: none;">
                    <div class="brand-icon">
                        <svg viewBox="0 0 24 24">
                            <path
                                d="M20 6h-4V4c0-1.1-.9-2-2-2h-4c-1.1 0-2 .9-2 2v2H4c-1.1 0-2 .9-2 2v11c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-8-2h4v2h-4V4zm8 15H4V8h16v11z">
                            </path>
                        </svg>
                    </div>
                    <span class="brand-title">CareerStudio</span>
                    <span class="workspace-badge"><?= e($workspaceTag) ?></span>
                </a>
                <div class="divider-v hide-mobile"></div>
                <div class="user-session-info hide-mobile">
                    <div class="user-identity">
                        <span class="user-name-bold"><?= e($userName) ?></span>
                        <span class="role-badge"><?= e(strtoupper($userRole)) ?></span>
                    </div>
                    <div class="session-timestamp">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                                stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                        </svg>
                        <span><?= e($todayDateStr) ?></span>
                    </div>
                </div>
            </div>

            <!-- Wide Header Search Bar -->
            <div class="header-search hide-tablet">
                <?php
                $isRecruiter = is_logged_in() && current_user_role() === 'recruiter';
                $searchAction = $isRecruiter ? 'recruiter-dashboard.php' : 'jobs.php';
                $searchPlaceholder = $isRecruiter ? 'Search candidate by name, role, skills...' : 'Search Jobs, Companies, Skills...';
                $searchValue = query('q') !== '' ? query('q') : query('title');
                ?>
                <form method="get" action="<?= e($searchAction) ?>" class="search-input-wrapper">
                    <?php if ($isRecruiter): ?>
                        <input type="hidden" name="tab" value="applicants">
                    <?php endif; ?>
                    <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2"></path>
                    </svg>
                    <input type="text" name="q" placeholder="<?= e($searchPlaceholder) ?>" value="<?= e($searchValue) ?>" />
                </form>
            </div>  

            <!-- User Profile & Notifications -->
            <div class="header-right">
                <a href="<?= e($notifyHref) ?>" class="icon-btn" title="Notifications">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path
                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
                            stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                    </svg>
                    <?php if ($notifications > 0): ?>
                        <span class="notification-badge"><?= $notifications > 9 ? '9+' : $notifications ?></span>
                    <?php endif; ?>
                </a>
                <div class="divider-v"></div>
                <div class="user-profile-wrapper" style="position: relative;">
                    <button type="button" class="user-profile" onclick="toggleUserDropdown(event)" aria-expanded="false" style="background: none; border: none; padding: 0.25rem 0.5rem; cursor: pointer; display: flex; align-items: center; gap: 0.75rem; border-radius: 0.5rem; transition: background 0.2s;">
                        <div class="avatar" style="overflow: hidden; width: 34px; height: 34px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--slate-800); color: #fff; flex-shrink: 0;">
                            <?php if ($userAvatar && is_file(UPLOAD_DIR . basename($userAvatar))): ?>
                                <img src="uploads/<?= e(basename($userAvatar)) ?>" alt="<?= e($userName) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <?= e($userInitials) ?>
                            <?php endif; ?>
                        </div>
                        <div class="profile-details hide-mobile" style="text-align: left;">
                            <div class="profile-name" style="font-weight: 600; font-size: 0.875rem; color: var(--slate-800);"><?= e(first_name($userName)) ?></div>
                            <div class="profile-title" style="font-size: 0.75rem; color: var(--slate-500);"><?= e($userTitle) ?></div>
                        </div>
                        <svg class="dropdown-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width: 16px; height: 16px; color: var(--slate-400); transition: transform 0.2s;">
                            <path d="M19 9l-7 7-7-7" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                        </svg>
                    </button>
                    <div id="userProfileDropdown" class="user-dropdown-menu" style="display: none; position: absolute; right: 0; top: calc(100% + 8px); width: 200px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 0.75rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1); z-index: 1000; overflow: hidden; padding: 0.375rem 0;">
                        <div style="padding: 0.625rem 1rem; border-bottom: 1px solid #f1f5f9; margin-bottom: 0.25rem;">
                            <div style="font-weight: 700; font-size: 0.875rem; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= e($userName) ?></div>
                            <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px; text-transform: uppercase; font-weight: 600; letter-spacing: 0.025em;"><?= e($userRole) ?></div>
                        </div>
                        <a href="profile.php" class="user-dropdown-item" style="display: flex; align-items: center; gap: 0.625rem; padding: 0.625rem 1rem; font-size: 0.875rem; font-weight: 500; color: #334155; text-decoration: none; transition: background 0.15s, color 0.15s;">
                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.9 3.1-6 7-6s7 2.1 7 6"/></svg>
                            <span>My Profile</span>
                        </a>
                        <div style="border-top: 1px solid #f1f5f9; margin: 0.25rem 0;"></div>
                        <form method="POST" action="logout.php" style="margin: 0; padding: 0;">
                            <?= csrf_field() ?>
                            <button type="submit" class="user-dropdown-item danger" style="width: 100%; border: none; background: none; display: flex; align-items: center; gap: 0.625rem; padding: 0.625rem 1rem; font-size: 0.875rem; font-weight: 500; color: #dc2626; text-decoration: none; cursor: pointer; font-family: inherit; text-align: left; transition: background 0.15s, color 0.15s;">
                                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M15 17l5-5-5-5"/><path d="M20 12H9"/><path d="M12 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6"/></svg>
                                <span>Log Out</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <style>
            .user-profile:hover {
                background: #f1f5f9 !important;
            }
            .user-dropdown-item:hover {
                background-color: #f8fafc;
                color: #4f46e5 !important;
            }
            .user-dropdown-item.danger:hover {
                background-color: #fef2f2;
                color: #dc2626 !important;
            }
        </style>

        <script>
            function toggleUserDropdown(event) {
                event.stopPropagation();
                const dropdown = document.getElementById('userProfileDropdown');
                const isVisible = dropdown.style.display === 'block';
                dropdown.style.display = isVisible ? 'none' : 'block';
            }

            document.addEventListener('click', function(event) {
                const dropdown = document.getElementById('userProfileDropdown');
                if (dropdown && dropdown.style.display === 'block') {
                    if (!dropdown.contains(event.target)) {
                        dropdown.style.display = 'none';
                    }
                }
            });
        </script>

        <div class="app-layout">
            <!-- Categorized Left Navigation -->
            <aside class="sidebar">
                <div class="sidebar-nav-container">
                    <?php if ($userRole === 'recruiter'): ?>
                        <div class="nav-section">
                            <div class="section-title">OVERVIEW</div>
                            <nav class="nav-group">
                                <a href="recruiter-dashboard.php?tab=pipeline"
                                    class="nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
                                            stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                                    </svg>
                                    <span>Dashboard</span>
                                </a>
                                <a href="recruiter-dashboard.php?tab=jobs"
                                    class="nav-link <?= $activeNav === 'myjobs' ? 'active' : '' ?>">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                            d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                                            stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                                    </svg>
                                    <span>My Jobs</span>
                                </a>
                            </nav>
                        </div>

                        <div class="nav-section">
                            <div class="section-title">CANDIDATE MANAGEMENT</div>
                            <nav class="nav-group">
                                <a href="recruiter-dashboard.php?tab=applicants"
                                    class="nav-link <?= $activeNav === 'applicants' ? 'active' : '' ?>">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                            d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"
                                            stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                                    </svg>
                                    <span>All Applicants</span>
                                </a>
                                
                                
                            </nav>
                        </div>
                    <?php else: ?>
                        <div class="nav-section">
                            <div class="section-title">OVERVIEW</div>
                            <nav class="nav-group">
                                <a href="student-dashboard.php"
                                    class="nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                            d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"
                                            stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                                    </svg>
                                    <span>Dashboard</span>
                                </a>
                                <a href="applications-tracker.php" class="nav-link <?= $activeNav === 'tracker' ? 'active' : '' ?>">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                            d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                            stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                                    </svg>
                                    <span>Application Tracker</span>
                                </a>
                                <a href="jobs.php" class="nav-link <?= $activeNav === 'jobs' ? 'active' : '' ?>">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path
                                            d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                                            stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                                    </svg>
                                    <span>Browse Jobs</span>
                                </a>
                            </nav>
                        </div>

                        <div class="nav-section">
                            <div class="section-title">MY APPLICATIONS</div>
                            <nav class="nav-group">
                                <a href="wishlist.php" class="nav-link <?= $activeNav === 'saved' ? 'active' : '' ?>">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" stroke-linecap="round"
                                            stroke-linejoin="round" stroke-width="2"></path>
                                    </svg>
                                    <span>Saved / Wishlist</span>
                                </a>
                            </nav>
                        </div>

                        <div class="nav-section">
                            <div class="section-title">ACCOUNT &amp; PROFILE</div>
                            <nav class="nav-group">
                                <a href="profile.php" class="nav-link <?= $activeNav === 'profile' ? 'active' : '' ?>">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                                            stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                                    </svg>
                                    <span>Profile &amp; Resume</span>
                                </a>
                                
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="sidebar-footer">
                    <div class="status-indicator">
                        <span class="status-dot"></span>
                        <span>Portal Online</span>
                    </div>
                    <form method="post" action="logout.php">
                        <?= csrf_field() ?>
                        <button type="submit" class="logout-btn" title="Log Out">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
                                    stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                            </svg>
                        </button>
                    </form>
                </div>
            </aside>

            <!-- Main Workspace Content -->
            <main class="main-content">

            <?php else: ?>
                <!-- ============ Public shell: horizontal navbar ============ -->
                <nav class="navbar">
                    <div class="navbar-inner">
                        <a href="index.php" class="brand" style="display: flex; align-items: center; gap: 8px;">
                            <span class="material-symbols-outlined"
                                style="font-variation-settings: 'FILL' 1; font-size: 24px;">work</span>
                            CareerStudio
                        </a>
                        <div class="nav-links">
                            <a href="jobs.php" <?= $activeNav === 'jobs' ? ' class="active"' : '' ?>>Browse Jobs</a>
                            <?php if (is_logged_in()): ?>
                                <a href="<?= e(dashboard_url()) ?>" <?= $activeNav === 'dashboard' ? ' class="active"' : '' ?>>Dashboard</a>
                                <a href="profile.php" <?= $activeNav === 'profile' ? ' class="active"' : '' ?>>Profile</a>
                                <span class="nav-user"><?= e(current_user_name()) ?></span>
                                <form method="post" action="logout.php" class="nav-form">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-secondary btn-sm">Logout</button>
                                </form>
                            <?php else: ?>
                                <a href="login.php" <?= $activeNav === 'login' ? ' class="active"' : '' ?>>Login</a>
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
                                <span
                                    class="toast-title"><?= $f['type'] === 'error' ? 'Something went wrong' : 'Success' ?></span>
                                <?= e($f['message']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>