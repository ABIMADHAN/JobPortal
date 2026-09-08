<?php
/**
 * notifications-page.php
 * Dedicated Notifications Center for students & recruiters.
 * Real-time event history, date/type filters, mark as read, and email preferences.
 * Fully SPA-enabled: tab switching, instant filtering, and AJAX form submissions with zero full-page reloads.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();

$pdo = get_db();
ensure_notifications_table($pdo);

$userId = (int) current_user_id();
$role = (string) current_user_role();

// ---------------------------------------------------------------
// POST Handlers (AJAX & Standard)
// ---------------------------------------------------------------
if (is_post()) {
    verify_csrf();
    $action = post('action');
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || post('ajax') === '1';

    if ($action === 'mark_read') {
        $notifId = (int) post('notification_id');
        $stmt = $pdo->prepare('UPDATE user_notifications SET is_read = 1 WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $notifId, ':uid' => $userId]);

        $unreadCount = get_unread_notification_count($pdo, $userId);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'unread_count' => $unreadCount]);
            exit;
        }

        flash('Notification marked as read.');
        redirect('notifications-page.php');
    }

    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE user_notifications SET is_read = 1 WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'unread_count' => 0]);
            exit;
        }

        flash('All notifications marked as read.');
        redirect('notifications-page.php');
    }

    if ($action === 'clear_all') {
        $stmt = $pdo->prepare('DELETE FROM user_notifications WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'unread_count' => 0, 'total_count' => 0]);
            exit;
        }

        flash('Notification history cleared.');
        redirect('notifications-page.php');
    }

    if ($action === 'save_email_prefs') {
        $_SESSION['email_pref_app'] = post('pref_app') === '1' ? 1 : 0;
        $_SESSION['email_pref_interview'] = post('pref_interview') === '1' ? 1 : 0;
        $_SESSION['email_pref_status'] = post('pref_status') === '1' ? 1 : 0;
        $_SESSION['email_pref_digest'] = post('pref_digest') === '1' ? 1 : 0;

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Email preferences saved successfully.']);
            exit;
        }

        flash('Email preferences saved successfully.');
        redirect('notifications-page.php?tab=preferences');
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }
    redirect('notifications-page.php');
}

// ---------------------------------------------------------------
// GET Data Fetching & Seeding
// ---------------------------------------------------------------
$filterType = query('type', 'all');
$filterDate = query('date', 'all');
$activeTab = query('tab', 'feed');

// Fetch recorded notifications
$stmt = $pdo->prepare('SELECT * FROM user_notifications WHERE user_id = :uid ORDER BY created_at DESC');
$stmt->execute([':uid' => $userId]);
$allNotificationsList = $stmt->fetchAll();

// Seed initial notifications from application records if table is currently empty for this user
if (empty($allNotificationsList)) {
    if ($role === 'recruiter') {
        $stmtApps = $pdo->prepare(
            'SELECT a.id, a.status, a.applied_at, a.interview_at, j.title AS job_title, u.full_name AS student_name
             FROM applications a
             INNER JOIN jobs j ON a.job_id = j.id
             INNER JOIN companies c ON j.company_id = c.id
             INNER JOIN users u ON a.student_id = u.id
             WHERE c.user_id = :uid
             ORDER BY a.applied_at DESC LIMIT 10'
        );
        $stmtApps->execute([':uid' => $userId]);
        foreach ($stmtApps->fetchAll() as $appRow) {
            create_notification(
                $pdo,
                $userId,
                'Candidate Application: ' . $appRow['job_title'],
                $appRow['student_name'] . ' submitted an application for ' . $appRow['job_title'] . '.',
                'application',
                'recruiter-dashboard.php?tab=applicants'
            );
            if (!empty($appRow['interview_at'])) {
                create_notification(
                    $pdo,
                    $userId,
                    'Scheduled Interview: ' . $appRow['student_name'],
                    'Interview scheduled for ' . format_datetime($appRow['interview_at']) . ' with ' . $appRow['student_name'] . '.',
                    'interview',
                    'recruiter-dashboard.php?tab=pipeline'
                );
            }
        }
    } else {
        $stmtApps = $pdo->prepare(
            'SELECT a.id, a.status, a.applied_at, a.interview_at, j.title AS job_title, c.company_name
             FROM applications a
             INNER JOIN jobs j ON a.job_id = j.id
             INNER JOIN companies c ON j.company_id = c.id
             WHERE a.student_id = :uid
             ORDER BY a.applied_at DESC LIMIT 10'
        );
        $stmtApps->execute([':uid' => $userId]);
        foreach ($stmtApps->fetchAll() as $appRow) {
            create_notification(
                $pdo,
                $userId,
                'Application Received: ' . $appRow['job_title'],
                'Your application for ' . $appRow['job_title'] . ' at ' . $appRow['company_name'] . ' was submitted successfully.',
                'application',
                'student-dashboard.php'
            );
            if (!empty($appRow['interview_at'])) {
                create_notification(
                    $pdo,
                    $userId,
                    'Interview Invitation: ' . $appRow['company_name'],
                    'Interview scheduled for ' . format_datetime($appRow['interview_at']) . ' with ' . $appRow['company_name'] . '.',
                    'interview',
                    'student-dashboard.php'
                );
            }
        }
    }

    // Re-fetch after seeding
    $stmt = $pdo->prepare('SELECT * FROM user_notifications WHERE user_id = :uid ORDER BY created_at DESC');
    $stmt->execute([':uid' => $userId]);
    $allNotificationsList = $stmt->fetchAll();
}

// Calculate unread count metric
$unreadCount = 0;
foreach ($allNotificationsList as $n) {
    if ((int)$n['is_read'] === 0) {
        $unreadCount++;
    }
}

$pageTitle = 'Notifications Center';
$topbarTitle = 'Notifications Center';
$activeNav = 'notifications';
$layout = 'app';

require __DIR__ . '/header.php';
?>

<style>
    .notif-container {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        width: 100%;
        max-width: 80rem;
        margin: 0 auto;
        padding: 0.5rem 0;
    }

    .notif-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .notif-title {
        font-size: 1.875rem;
        font-weight: 800;
        letter-spacing: -0.025em;
        color: var(--slate-900);
    }

    .notif-subtitle {
        font-size: 0.875rem;
        color: var(--slate-500);
        margin-top: 0.25rem;
    }

    .metrics-grid-notif {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1.25rem;
    }

    .metric-card-notif {
        background: #ffffff;
        border: 1px solid var(--slate-200);
        border-radius: 1rem;
        padding: 1.25rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }

    .metric-val-notif {
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--slate-900);
    }

    .metric-lbl-notif {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--slate-500);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-top: 0.25rem;
    }

    .notif-tabs {
        display: flex;
        gap: 1rem;
        border-bottom: 1px solid var(--slate-200);
        margin-bottom: 1rem;
    }

    .notif-tab-btn {
        padding: 0.75rem 1rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--slate-500);
        border: none;
        background: transparent;
        border-bottom: 2px solid transparent;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.2s;
    }

    .notif-tab-btn:hover {
        color: var(--slate-800);
    }

    .notif-tab-btn.active {
        color: var(--brand-600);
        border-color: var(--brand-600);
    }

    .filter-bar-notif {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
        background: #ffffff;
        padding: 1rem 1.25rem;
        border-radius: 0.875rem;
        border: 1px solid var(--slate-200);
        margin-bottom: 1rem;
    }

    .pill-filter-notif {
        display: inline-flex;
        align-items: center;
        padding: 0.375rem 0.875rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--slate-600);
        background: var(--slate-100);
        text-decoration: none;
        transition: all 0.2s;
        border: none;
        cursor: pointer;
    }

    .pill-filter-notif:hover, .pill-filter-notif.active {
        background: var(--slate-900);
        color: #ffffff;
    }

    .notif-item-card {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        background: #ffffff;
        border: 1px solid var(--slate-200);
        border-radius: 0.875rem;
        padding: 1.25rem;
        transition: all 0.15s;
    }

    .notif-item-card.unread {
        border-left: 4px solid var(--brand-600);
        background: rgba(238, 242, 255, 0.25);
    }

    .type-badge-notif {
        padding: 0.25rem 0.625rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .badge-interview { background: var(--emerald-50); color: var(--emerald-700); border: 1px solid var(--emerald-100); }
    .badge-application { background: var(--brand-50); color: var(--brand-700); border: 1px solid var(--brand-100); }
    .badge-system { background: var(--slate-100); color: var(--slate-700); }

    .switch-toggle-notif {
        position: relative;
        display: inline-block;
        width: 48px;
        height: 26px;
        flex-shrink: 0;
    }
    .switch-toggle-notif input {
        opacity: 0;
        width: 0;
        height: 0;
        margin: 0;
    }
    .slider-notif {
        position: absolute;
        cursor: pointer;
        top: 0; left: 0; right: 0; bottom: 0;
        background-color: #94a3b8;
        border: 1px solid #64748b;
        transition: all 0.25s ease-in-out;
        border-radius: 9999px;
        box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.15);
    }
    .slider-notif:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: #ffffff;
        transition: all 0.25s ease-in-out;
        border-radius: 50%;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.25);
    }
    input:checked + .slider-notif {
        background-color: #2563eb;
        border-color: #1d4ed8;
    }
    input:checked + .slider-notif:before {
        transform: translateX(22px);
    }
    .switch-toggle-notif:hover .slider-notif {
        filter: brightness(0.95);
    }

    .toast-alert-spa {
        padding: 0.75rem 1rem;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #065f46;
        border-radius: 0.5rem;
        font-size: 0.84375rem;
        font-weight: 600;
        margin-bottom: 1rem;
        display: none;
    }

    @media (max-width: 768px) {
        .metrics-grid-notif { grid-template-columns: 1fr; }
    }
</style>

<main class="canvas">
    <div class="notif-container">
        <!-- Page Header -->
        <div class="notif-header">
            <div>
                <h1 class="notif-title">Notifications Center</h1>
                <p class="notif-subtitle">Track real-time application updates, scheduled interviews, and manage email notification preferences.</p>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <form id="mark-all-read-form" method="POST" action="notifications-page.php" onsubmit="handleMarkAllRead(event)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="pill-filter-notif active">Check &amp; Mark All Read</button>
                </form>
                <form id="clear-all-form" method="POST" action="notifications-page.php" onsubmit="handleClearAll(event)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="clear_all">
                    <button type="submit" class="pill-filter-notif" style="color: var(--rose-600); background: var(--rose-50);">Clear History</button>
                </form>
            </div>
        </div>

        <!-- Metrics Overview -->
        <div class="metrics-grid-notif">
            <div class="metric-card-notif">
                <div class="metric-val-notif" id="metric-unread-count" style="color: var(--brand-600);"><?= $unreadCount ?></div>
                <div class="metric-lbl-notif">Unread Updates</div>
            </div>
            <div class="metric-card-notif">
                <div class="metric-val-notif" id="metric-total-count"><?= count($allNotificationsList) ?></div>
                <div class="metric-lbl-notif">Total Activity Logs</div>
            </div>
            <div class="metric-card-notif">
                <div class="metric-val-notif" style="color: var(--emerald-600);">Active</div>
                <div class="metric-lbl-notif">Email Dispatch Service</div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="notif-tabs">
            <button type="button" onclick="switchNotifTab('feed')" id="tab-btn-feed" class="notif-tab-btn <?= $activeTab === 'feed' ? 'active' : '' ?>">
                Activity Feed (<span id="feed-tab-count"><?= count($allNotificationsList) ?></span>)
            </button>
            <button type="button" onclick="switchNotifTab('preferences')" id="tab-btn-preferences" class="notif-tab-btn <?= $activeTab === 'preferences' ? 'active' : '' ?>">
                Email Preferences
            </button>
        </div>

        <!-- TAB 1: Activity Feed -->
        <div id="tab-content-feed" style="<?= $activeTab === 'preferences' ? 'display: none;' : '' ?>">
            <!-- Filter Sub-bar -->
            <div class="filter-bar-notif">
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button type="button" onclick="filterNotifsSPA('all', null)" class="pill-filter-notif filter-type-pill <?= $filterType === 'all' ? 'active' : '' ?>" data-type="all">All Types</button>
                    <button type="button" onclick="filterNotifsSPA('unread', null)" class="pill-filter-notif filter-type-pill <?= $filterType === 'unread' ? 'active' : '' ?>" data-type="unread">Unread Only</button>
                    <button type="button" onclick="filterNotifsSPA('interview', null)" class="pill-filter-notif filter-type-pill <?= $filterType === 'interview' ? 'active' : '' ?>" data-type="interview">Interviews</button>
                    <button type="button" onclick="filterNotifsSPA('application', null)" class="pill-filter-notif filter-type-pill <?= $filterType === 'application' ? 'active' : '' ?>" data-type="application">Applications</button>
                </div>

                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <label style="font-size: 0.75rem; font-weight: 600; color: var(--slate-600); text-transform: uppercase;">Date Range:</label>
                    <select id="date-range-select" onchange="filterNotifsSPA(null, this.value)" style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; border: 1px solid var(--slate-200); font-size: 0.8125rem; font-weight: 500;">
                        <option value="all" <?= $filterDate === 'all' ? 'selected' : '' ?>>All Time</option>
                        <option value="today" <?= $filterDate === 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="this_week" <?= $filterDate === 'this_week' ? 'selected' : '' ?>>This Week</option>
                        <option value="this_month" <?= $filterDate === 'this_month' ? 'selected' : '' ?>>This Month</option>
                    </select>
                </div>
            </div>

            <!-- Activity List Container -->
            <div id="notif-activity-list" style="display: flex; flex-direction: column; gap: 0.75rem;">
                <?php foreach ($allNotificationsList as $item): ?>
                    <?php 
                        $itemTs = strtotime($item['created_at']);
                        $itemDateStr = date('Y-m-d', $itemTs);
                    ?>
                    <div class="notif-item-card <?= (int)$item['is_read'] === 0 ? 'unread' : '' ?>" 
                         data-id="<?= (int)$item['id'] ?>"
                         data-type="<?= e($item['type']) ?>"
                         data-read="<?= (int)$item['is_read'] ?>"
                         data-timestamp="<?= $itemTs ?>"
                         data-date="<?= $itemDateStr ?>">
                        <div style="display: flex; gap: 1rem; flex: 1;">
                            <div style="margin-top: 0.25rem;">
                                <span class="type-badge-notif <?= $item['type'] === 'interview' ? 'badge-interview' : ($item['type'] === 'application' ? 'badge-application' : 'badge-system') ?>">
                                    <?= e(ucfirst($item['type'])) ?>
                                </span>
                            </div>
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 0.625rem; flex-wrap: wrap;">
                                    <h4 style="font-size: 0.9375rem; font-weight: 700; color: var(--slate-900); margin: 0;"><?= e($item['title']) ?></h4>
                                    <span style="font-size: 0.75rem; color: var(--slate-400);"><?= e(format_datetime($item['created_at'])) ?></span>
                                </div>
                                <p style="font-size: 0.84375rem; color: var(--slate-600); margin: 0.375rem 0 0 0; line-height: 1.4;"><?= e($item['message']) ?></p>
                                
                                <?php if (!empty($item['link'])): ?>
                                    <a href="<?= e($item['link']) ?>" style="display: inline-flex; align-items: center; gap: 0.375rem; font-size: 0.75rem; font-weight: 600; color: var(--brand-600); text-decoration: none; margin-top: 0.5rem;">
                                        View Details &rarr;
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ((int)$item['is_read'] === 0): ?>
                            <form class="mark-read-single-form" method="POST" action="notifications-page.php" onsubmit="handleMarkSingleRead(event, <?= (int)$item['id'] ?>)" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="notification_id" value="<?= (int)$item['id'] ?>">
                                <button type="submit" style="background: none; border: none; font-size: 0.75rem; font-weight: 600; color: var(--slate-500); cursor: pointer; padding: 0.25rem 0.5rem;">
                                    Mark Read
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div id="no-notif-empty-state" class="metric-card-notif" style="text-align: center; padding: 3rem 1.5rem; <?= empty($allNotificationsList) ? '' : 'display: none;' ?>">
                    <p style="color: var(--slate-500); font-size: 0.875rem;">No notifications found for the selected filters.</p>
                </div>
            </div>
        </div>

        <!-- TAB 2: Email Preferences -->
        <div id="tab-content-preferences" style="<?= $activeTab === 'preferences' ? '' : 'display: none;' ?>">
            <div class="metric-card-notif" style="max-width: 48rem;">
                <div id="prefs-toast-msg" class="toast-alert-spa">Email preferences saved successfully!</div>

                <div style="margin-bottom: 1.5rem;">
                    <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem;">Email Alert Preferences</h3>
                    <p style="font-size: 0.8125rem; color: var(--slate-500); margin: 0;">Configure which transactional emails are dispatched to your address (<?= e($_SESSION['email'] ?? 'your account') ?>).</p>
                </div>

                <form method="POST" action="notifications-page.php" id="email-prefs-form" onsubmit="handleSaveEmailPrefs(event)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_email_prefs">

                    <!-- Master Switch for All Options -->
                    <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; background: var(--slate-50); border-radius: 0.875rem; border: 1px solid var(--slate-200); margin-bottom: 1.5rem;">
                        <div>
                            <div style="font-weight: 700; font-size: 0.9375rem; color: var(--slate-900);">Master Switch (Toggle All)</div>
                            <div style="font-size: 0.75rem; color: var(--slate-600);">Enable or disable all email notification features with a single click</div>
                        </div>
                        <label class="switch-toggle-notif">
                            <input type="checkbox" id="toggle-all-switch" onchange="toggleAllEmailPrefs(this.checked)">
                            <span class="slider-notif"></span>
                        </label>
                    </div>

                    <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                        <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 1rem; border-bottom: 1px solid var(--slate-100);">
                            <div>
                                <div style="font-weight: 600; font-size: 0.875rem; color: var(--slate-800);">Application Updates</div>
                                <div style="font-size: 0.75rem; color: var(--slate-500);">Receive email when candidate submits or status changes</div>
                            </div>
                            <label class="switch-toggle-notif">
                                <input type="checkbox" class="pref-switch" name="pref_app" value="1" <?= ($_SESSION['email_pref_app'] ?? 1) ? 'checked' : '' ?> onchange="updateMasterSwitchState()">
                                <span class="slider-notif"></span>
                            </label>
                        </div>

                        <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 1rem; border-bottom: 1px solid var(--slate-100);">
                            <div>
                                <div style="font-weight: 600; font-size: 0.875rem; color: var(--slate-800);">Interview Invites &amp; Slots</div>
                                <div style="font-size: 0.75rem; color: var(--slate-500);">Receive instant email notifications for scheduled interview slots</div>
                            </div>
                            <label class="switch-toggle-notif">
                                <input type="checkbox" class="pref-switch" name="pref_interview" value="1" <?= ($_SESSION['email_pref_interview'] ?? 1) ? 'checked' : '' ?> onchange="updateMasterSwitchState()">
                                <span class="slider-notif"></span>
                            </label>
                        </div>

                        <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 1rem; border-bottom: 1px solid var(--slate-100);">
                            <div>
                                <div style="font-weight: 600; font-size: 0.875rem; color: var(--slate-800);">Status Change Notifications</div>
                                <div style="font-size: 0.75rem; color: var(--slate-500);">Receive emails when your profile is shortlisted, hired, or reviewed</div>
                            </div>
                            <label class="switch-toggle-notif">
                                <input type="checkbox" class="pref-switch" name="pref_status" value="1" <?= ($_SESSION['email_pref_status'] ?? 1) ? 'checked' : '' ?> onchange="updateMasterSwitchState()">
                                <span class="slider-notif"></span>
                            </label>
                        </div>

                        <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 1rem;">
                            <div>
                                <div style="font-weight: 600; font-size: 0.875rem; color: var(--slate-800);">Daily Summary Digest</div>
                                <div style="font-size: 0.75rem; color: var(--slate-500);">Receive a daily overview email summarizing all pipeline activities</div>
                            </div>
                            <label class="switch-toggle-notif">
                                <input type="checkbox" class="pref-switch" name="pref_digest" value="1" <?= ($_SESSION['email_pref_digest'] ?? 0) ? 'checked' : '' ?> onchange="updateMasterSwitchState()">
                                <span class="slider-notif"></span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="pill-filter-notif active" style="margin-top: 1.5rem; padding: 0.625rem 1.5rem; font-size: 0.875rem;">Save Preferences</button>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
    let currentFilterType = '<?= e($filterType) ?>';
    let currentFilterDate = '<?= e($filterDate) ?>';
    let currentTab = '<?= e($activeTab) ?>';

    function updateHeaderBadge(count) {
        const badge = document.querySelector('.icon-btn[title="Notifications"] .notification-badge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 9 ? '9+' : count;
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    function switchNotifTab(tabName) {
        currentTab = tabName;
        document.getElementById('tab-content-feed').style.display = tabName === 'feed' ? 'block' : 'none';
        document.getElementById('tab-content-preferences').style.display = tabName === 'preferences' ? 'block' : 'none';

        document.getElementById('tab-btn-feed').classList.toggle('active', tabName === 'feed');
        document.getElementById('tab-btn-preferences').classList.toggle('active', tabName === 'preferences');

        updateURLParams();
    }

    function filterNotifsSPA(type, date) {
        if (type !== null) currentFilterType = type;
        if (date !== null) currentFilterDate = date;

        // Update pill active classes
        document.querySelectorAll('.filter-type-pill').forEach(pill => {
            pill.classList.toggle('active', pill.dataset.type === currentFilterType);
        });

        // Update select dropdown
        const selectEl = document.getElementById('date-range-select');
        if (selectEl) selectEl.value = currentFilterDate;

        const items = document.querySelectorAll('.notif-item-card');
        let visibleCount = 0;

        const nowSec = Math.floor(Date.now() / 1000);
        const todayStr = new Date().toISOString().slice(0, 10);
        const sevenDaysAgoSec = nowSec - (7 * 86400);
        const thirtyDaysAgoSec = nowSec - (30 * 86400);

        items.forEach(item => {
            const isRead = parseInt(item.dataset.read, 10);
            const itemType = item.dataset.type;
            const itemTs = parseInt(item.dataset.timestamp, 10);
            const itemDateStr = item.dataset.date;

            let matchType = true;
            if (currentFilterType === 'unread' && isRead !== 0) matchType = false;
            if (currentFilterType === 'interview' && itemType !== 'interview') matchType = false;
            if (currentFilterType === 'application' && itemType !== 'application') matchType = false;

            let matchDate = true;
            if (currentFilterDate === 'today' && itemDateStr !== todayStr) matchDate = false;
            if (currentFilterDate === 'this_week' && itemTs < sevenDaysAgoSec) matchDate = false;
            if (currentFilterDate === 'this_month' && itemTs < thirtyDaysAgoSec) matchDate = false;

            if (matchType && matchDate) {
                item.style.display = 'flex';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });

        const emptyState = document.getElementById('no-notif-empty-state');
        if (emptyState) {
            emptyState.style.display = (visibleCount === 0) ? 'block' : 'none';
        }

        const countBadge = document.getElementById('feed-tab-count');
        if (countBadge) countBadge.textContent = visibleCount;

        updateURLParams();
    }

    function updateURLParams() {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', currentTab);
        url.searchParams.set('type', currentFilterType);
        url.searchParams.set('date', currentFilterDate);
        window.history.pushState({}, '', url.toString());
    }

    // Toggle All Email Preferences
    function toggleAllEmailPrefs(isChecked) {
        const switches = document.querySelectorAll('.pref-switch');
        switches.forEach(sw => sw.checked = isChecked);
    }

    function updateMasterSwitchState() {
        const switches = Array.from(document.querySelectorAll('.pref-switch'));
        const masterSwitch = document.getElementById('toggle-all-switch');
        if (!masterSwitch) return;
        const allChecked = switches.every(sw => sw.checked);
        const anyChecked = switches.some(sw => sw.checked);
        masterSwitch.checked = allChecked;
        masterSwitch.indeterminate = !allChecked && anyChecked;
    }

    // Handle AJAX Mark Single Read
    function handleMarkSingleRead(event, notifId) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        formData.append('ajax', '1');

        fetch('notifications-page.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const card = document.querySelector(`.notif-item-card[data-id="${notifId}"]`);
                if (card) {
                    card.classList.remove('unread');
                    card.dataset.read = '1';
                    if (form) form.remove();
                }
                const unreadEl = document.getElementById('metric-unread-count');
                if (unreadEl) unreadEl.textContent = data.unread_count;
                updateHeaderBadge(data.unread_count);

                // Re-apply filter if currently viewing unread only
                filterNotifsSPA(null, null);
            }
        })
        .catch(err => console.error('Error marking notification as read:', err));
    }

    // Handle AJAX Mark All Read
    function handleMarkAllRead(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        formData.append('ajax', '1');

        fetch('notifications-page.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notif-item-card').forEach(card => {
                    card.classList.remove('unread');
                    card.dataset.read = '1';
                });
                document.querySelectorAll('.mark-read-single-form').forEach(f => f.remove());

                const unreadEl = document.getElementById('metric-unread-count');
                if (unreadEl) unreadEl.textContent = '0';
                updateHeaderBadge(0);

                // Re-apply filter if currently viewing unread only
                filterNotifsSPA(null, null);
            }
        })
        .catch(err => console.error('Error marking all as read:', err));
    }

    // Handle AJAX Clear All with SweetAlert2
    function handleClearAll(event) {
        event.preventDefault();
        const form = event.target;

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'Clear Notification History?',
                text: 'Are you sure you want to delete all activity logs and notifications? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e11d48',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Yes, clear all history',
                cancelButtonText: 'Cancel',
                focusCancel: true,
                customClass: {
                    popup: 'swal2-modal-rounded'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    executeClearAll(form);
                }
            });
        } else {
            if (confirm('Clear all notification logs?')) {
                executeClearAll(form);
            }
        }
    }

    function executeClearAll(form) {
        const formData = new FormData(form);
        formData.append('ajax', '1');

        fetch('notifications-page.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: 'History Cleared!',
                        text: 'Your notification logs have been successfully deleted.',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    });
                }

                document.querySelectorAll('.notif-item-card').forEach(card => card.remove());
                const emptyState = document.getElementById('no-notif-empty-state');
                if (emptyState) emptyState.style.display = 'block';

                const unreadEl = document.getElementById('metric-unread-count');
                if (unreadEl) unreadEl.textContent = '0';

                const totalEl = document.getElementById('metric-total-count');
                if (totalEl) totalEl.textContent = '0';

                const countBadge = document.getElementById('feed-tab-count');
                if (countBadge) countBadge.textContent = '0';

                updateHeaderBadge(0);
            }
        })
        .catch(err => console.error('Error clearing notification history:', err));
    }

    // Handle AJAX Save Email Preferences
    function handleSaveEmailPrefs(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        formData.append('ajax', '1');

        fetch('notifications-page.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const toast = document.getElementById('prefs-toast-msg');
                if (toast) {
                    toast.style.display = 'block';
                    setTimeout(() => { toast.style.display = 'none'; }, 3500);
                }
            }
        })
        .catch(err => console.error('Error saving email preferences:', err));
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateMasterSwitchState();
        filterNotifsSPA(null, null);
    });
</script>

<?php require __DIR__ . '/footer.php'; ?>
