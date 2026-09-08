<?php
/**
 * student-dashboard.php
 * CareerStudio Candidate Application Pipeline & Tracker.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_role('student');

$pdo = get_db();
ensure_meeting_link_column($pdo);
$studentId = (int) current_user_id();

// ---------------------------------------------------------------
// POST: withdraw an application, or drop a job from the wishlist
// ---------------------------------------------------------------
if (is_post()) {
    verify_csrf();
    $action = post('action');

    if ($action === 'unsave') {
        $stmt = $pdo->prepare('DELETE FROM saved_jobs WHERE student_id = :uid AND job_id = :job');
        $stmt->execute([':uid' => $studentId, ':job' => (int) ($_POST['job_id'] ?? 0)]);
        flash('Removed from your wishlist.');
        redirect('student-dashboard.php');
    }

    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $stmt = $pdo->prepare(
        'UPDATE applications SET status = "withdrawn"
         WHERE id = :id AND student_id = :student_id AND status != "hired"'
    );
    $stmt->execute([':id' => $applicationId, ':student_id' => $studentId]);

    flash(
        $stmt->rowCount() === 0
            ? 'Application not found, or it can no longer be withdrawn.'
            : 'Application withdrawn.',
        $stmt->rowCount() === 0 ? 'error' : 'success'
    );
    redirect('student-dashboard.php');
}

// ---------------------------------------------------------------
// Stats & Database Queries
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_applications,
        SUM(CASE WHEN status IN ("applied", "under_review") THEN 1 ELSE 0 END) AS pending_applications,
        SUM(CASE WHEN status = "shortlisted" THEN 1 ELSE 0 END) AS shortlisted_applications,
        SUM(CASE WHEN status = "rejected" THEN 1 ELSE 0 END) AS rejected_applications,
        SUM(CASE WHEN status = "hired" THEN 1 ELSE 0 END) AS hired_applications
     FROM applications WHERE student_id = :student_id AND status != "withdrawn"'
);
$stmt->execute([':student_id' => $studentId]);
$stats = $stmt->fetch() ?: [];

$totalApplications = (int) ($stats['total_applications'] ?? 0);
$hiredApplications = (int) ($stats['hired_applications'] ?? 0);
$pendingApplications = (int) ($stats['pending_applications'] ?? 0);
$shortlistedApplications = (int) ($stats['shortlisted_applications'] ?? 0);
$successRate = $totalApplications > 0 ? (int) round($hiredApplications / $totalApplications * 100) : 0;

$stmt = $pdo->prepare('SELECT resume_path FROM student_profiles WHERE user_id = :uid LIMIT 1');
$stmt->execute([':uid' => $studentId]);
$profileRow = $stmt->fetch();
$hasResume = $profileRow && !empty($profileRow['resume_path']);

// Wishlist / Saved Jobs
$stmt = $pdo->prepare(
    'SELECT s.job_id, j.title, j.job_type, j.work_mode, j.location, c.company_name
     FROM saved_jobs s
     INNER JOIN jobs j ON s.job_id = j.id
     INNER JOIN companies c ON j.company_id = c.id
     WHERE s.student_id = :uid
     ORDER BY s.created_at DESC'
);
$stmt->execute([':uid' => $studentId]);
$savedJobs = $stmt->fetchAll();

// Applications List
$stmt = $pdo->prepare(
    'SELECT a.id AS application_id, a.status, a.applied_at, a.interview_at, a.meeting_link, a.notes,
            j.id AS job_id, j.title AS job_title, j.job_type, j.work_mode, j.location,
            c.company_name
     FROM applications a
     INNER JOIN jobs j ON a.job_id = j.id
     INNER JOIN companies c ON j.company_id = c.id
     WHERE a.student_id = :student_id
     ORDER BY a.applied_at DESC'
);
$stmt->execute([':student_id' => $studentId]);
$applications = $stmt->fetchAll();

$interviews = upcoming_interviews($pdo, 15);

$pageTitle = 'Candidate Dashboard';
$topbarTitle = 'Job Application Tracker';
$activeNav = 'dashboard';
$layout = 'app';
require __DIR__ . '/header.php';
?>

<!-- Main Workspace Content -->
<div style="display: flex; flex-direction: column; gap: 24px; width: 100%;">

    <!-- Page Title Header -->
    <div>
        <h1 class="page-title">Candidate Application Tracker</h1>
        <p class="page-subtitle">Track your job applications, scheduled technical rounds, and saved wishlist opportunities.</p>
    </div>

    <?php if (!$hasResume): ?>
        <div style="padding: 12px 18px; background: #fff1f2; border: 1px solid #ffe4e6; border-radius: 8px; color: #be123c; font-size: 0.75rem; font-weight: 500; display: flex; align-items: center; justify-content: space-between;">
            <span>⚠️ You haven't uploaded a resume yet — recruiters require a resume to review your applications.</span>
            <a href="profile.php" class="btn btn-rose" style="padding: 4px 10px; font-size: 11px;">Upload Resume &rarr;</a>
        </div>
    <?php endif; ?>

    <!-- Metric KPI Summary Cards (4 Cards) -->
    <section class="metrics-grid">
        <div class="card metric-card">
            <div>
                <div class="metric-label">Saved Wishlist</div>
                <div class="metric-value"><?= count($savedJobs) ?></div>
            </div>
            <div class="metric-icon icon-blue">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
            </div>
        </div>

        <div class="card metric-card">
            <div>
                <div class="metric-label">Total Applications</div>
                <div class="metric-value"><?= $totalApplications ?></div>
            </div>
            <div class="metric-icon icon-emerald">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
            </div>
        </div>

        <div class="card metric-card">
            <div>
                <div class="metric-label">In Review &amp; Shortlisted</div>
                <div class="metric-value"><?= $pendingApplications + $shortlistedApplications ?></div>
            </div>
            <div class="metric-icon icon-amber">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
            </div>
        </div>

        <div class="card metric-card">
            <div>
                <div class="metric-label">Offers &amp; Hired</div>
                <div class="metric-value"><?= $hiredApplications ?></div>
            </div>
            <div class="metric-icon icon-blue">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
            </div>
        </div>
    </section>

    <!-- Filter Bar & Export Actions -->
    <div class="action-bar flex-between">
        <div class="filter-group">
            <label class="filter-label">Filter Applications</label>
            <select class="select-control shadow-xs" id="statusFilter" onchange="filterStudentTable()">
                <option value="all">All Applications (<?= count($applications) ?>)</option>
                <option value="applied">Applied</option>
                <option value="under_review">Under Review</option>
                <option value="shortlisted">Shortlisted</option>
                <option value="hired">Hired</option>
                <option value="rejected">Rejected</option>
                <option value="withdrawn">Withdrawn</option>
            </select>
        </div>

        <div class="button-group-trio">
            <a href="jobs.php" class="btn btn-indigo shadow-xs">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                Find New Jobs
            </a>
            <a href="export.php" class="btn btn-rose shadow-xs">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                Export CSV
            </a>
            <a href="profile.php" class="btn btn-emerald shadow-xs">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                My Profile
            </a>
        </div>
    </div>

    <!-- Candidate Application Records Table Card -->
    <div class="card table-card">
        <!-- Search & Controls Sub-bar -->
        <div class="table-controls flex-between">
            <div class="records-selector">
                <span>Show</span>
                <select class="select-control select-sm">
                    <option>10</option>
                    <option>25</option>
                    <option>50</option>
                </select>
                <span>records</span>
            </div>
            <div class="table-search">
                <label>Search Application:</label>
                <input type="text" id="appSearchInput" class="input-search-sm" placeholder="Title, company, status..."/>
            </div>
        </div>

        <!-- Table Body -->
        <div class="table-wrapper">
            <?php if (empty($applications)): ?>
                <div class="empty-state" style="margin: 2rem auto;">
                    <div class="empty-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                    </div>
                    <div class="empty-title">No job applications submitted yet</div>
                    <p class="empty-description">Browse open positions and apply with your profile to start tracking opportunities.</p>
                    <a href="jobs.php" class="btn btn-indigo">+ Browse Jobs Now</a>
                </div>
            <?php else: ?>
                <table class="data-table" id="student-apps-table">
                    <thead>
                        <tr>
                            <th class="col-center" style="white-space: nowrap;">▲ S.No</th>
                            <th style="white-space: nowrap;">Application Ref</th>
                            <th style="white-space: nowrap;">Role &amp; Company</th>
                            <th style="white-space: nowrap;">Job Details</th>
                            <th style="white-space: nowrap;">Applied Date</th>
                            <th style="white-space: nowrap;">Status / Stage</th>
                            <th style="white-space: nowrap;">Interview Slot</th>
                            <th class="col-center" style="white-space: nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $sno = 1; foreach ($applications as $app): ?>
                        <tr data-status="<?= e($app['status']) ?>">
                            <td class="col-center text-subtle font-medium" style="white-space: nowrap;"><?= $sno++ ?></td>
                            <td class="cif-id" style="white-space: nowrap;">APP-<?= str_pad((string) $app['application_id'], 6, '0', STR_PAD_LEFT) ?></td>
                            <td style="white-space: nowrap;">
                                <div class="candidate-profile" style="display: flex; align-items: center; gap: 0.75rem; white-space: nowrap;">
                                    <div class="avatar-circle" style="flex-shrink: 0; width: 34px; height: 34px; border-radius: 50%; background: var(--slate-800); color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem;">
                                        <?= e(initials($app['company_name'])) ?>
                                    </div>
                                    <div style="display: flex; flex-direction: column; white-space: nowrap;">
                                        <div class="candidate-name" style="font-weight: 700; color: var(--slate-900); white-space: nowrap;">
                                            <a href="jobs.php?id=<?= (int) $app['job_id'] ?>" style="color: inherit; text-decoration: underline; white-space: nowrap;"><?= e($app['job_title']) ?></a>
                                        </div>
                                        <div class="candidate-email" style="font-size: 0.75rem; color: var(--slate-500); white-space: nowrap;"><?= e($app['company_name']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="white-space: nowrap;">
                                <div class="phone-number" style="white-space: nowrap; font-weight: 600;"><?= e(ucfirst($app['job_type'] ?: 'Full-time')) ?></div>
                                <div class="role-title" style="white-space: nowrap; font-size: 0.75rem; color: var(--slate-500);"><?= e(ucfirst($app['work_mode'] ?: 'Onsite')) ?> <?= $app['location'] ? '· ' . e($app['location']) : '' ?></div>
                            </td>
                            <td class="date-cell" style="white-space: nowrap;"><?= e(format_date($app['applied_at'])) ?></td>
                            <td style="white-space: nowrap;">
                                <?php
                                    $stageBadgeClass = match ($app['status']) {
                                        'under_review' => 'stage-sky',
                                        'shortlisted' => 'stage-purple',
                                        'hired' => 'stage-emerald',
                                        'rejected', 'withdrawn' => 'stage-rose',
                                        default => 'stage-sky',
                                    };
                                ?>
                                <span class="badge-stage <?= $stageBadgeClass ?>" style="white-space: nowrap;">
                                    <span class="stage-dot"></span>
                                    <?= e(status_label($app['status'])) ?>
                                </span>
                            </td>
                            <td style="white-space: nowrap;">
                                <?php if (!empty($app['interview_at']) && !in_array($app['status'], ['rejected', 'withdrawn'], true)): 
                                    $isIvPassed = strtotime($app['interview_at']) < time();
                                ?>
                                    <div style="display: flex; flex-direction: column; gap: 4px; align-items: flex-start;">
                                        <span class="badge-stage <?= $isIvPassed ? 'stage-slate' : 'stage-sky' ?>" style="font-size: 11px; white-space: nowrap; display: inline-flex; align-items: center; gap: 0.375rem;">📅 <?= e(format_datetime($app['interview_at'])) ?><?= $isIvPassed ? ' (Passed)' : '' ?></span>
                                        <?php if (!empty($app['meeting_link'])): ?>
                                            <a href="<?= e(safe_url($app['meeting_link'])) ?>" target="_blank" rel="noopener" class="btn <?= $isIvPassed ? 'btn-secondary' : 'btn-indigo' ?> shadow-xs" style="padding: 2px 8px; font-size: 10px; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; border-radius: 4px;">📹 <?= $isIvPassed ? 'Meeting Link' : 'Join Link &rarr;' ?></a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-subtle" style="font-size: 11px;">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="col-center">
                                <div class="action-buttons-trio">
                                    <a class="btn-action btn-view" href="jobs.php?id=<?= (int) $app['job_id'] ?>">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                                        View
                                    </a>
                                    <?php if (in_array($app['status'], ['applied', 'under_review', 'shortlisted'], true)): ?>
                                        <form method="post" action="student-dashboard.php" style="display: inline;" onsubmit="return confirm('Are you sure you want to withdraw this application?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="application_id" value="<?= (int) $app['application_id'] ?>">
                                            <button class="btn-action btn-delete" type="submit">
                                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                                                Withdraw
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Table Pagination -->
        <div class="table-pagination flex-between">
            <div>Showing 1 to <?= count($applications) ?> of <?= count($applications) ?> applications</div>
            <div class="pagination-controls">
                <button class="btn-page" disabled>|&lt;</button>
                <button class="btn-page" disabled>&lt;</button>
                <button class="btn-page active">1</button>
                <button class="btn-page" disabled>&gt;</button>
                <button class="btn-page" disabled>&gt;|</button>
            </div>
        </div>
    </div>

    <!-- Bottom Widgets Grid -->
    <section class="bottom-grid">
        <div class="card widget-card flex-2">
            <div class="widget-header flex-between">
                <div>
                    <h3 class="widget-title">Upcoming Interviews &amp; Schedule</h3>
                    <p class="widget-subtitle">Track scheduled rounds with hiring teams</p>
                </div>
                <a href="jobs.php" class="link-btn">Find More Opportunities →</a>
            </div>
            <?php if (empty($interviews)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                    </div>
                    <p class="empty-title">No interviews scheduled yet</p>
                    <p class="empty-description">When a recruiter shortlists you and assigns an interview slot, it will appear here automatically.</p>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 0.75rem; margin-top: 1rem; max-height: 260px; overflow-y: auto; padding-right: 0.375rem; scrollbar-width: thin;">
                    <?php foreach ($interviews as $iv): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; background: var(--slate-50); border-radius: 0.75rem; border: 1px solid var(--slate-200);">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <div class="avatar-circle"><?= e(initials((string) $iv['person'])) ?></div>
                                <div>
                                    <div class="candidate-name"><?= e($iv['person']) ?></div>
                                    <div class="role-title"><?= e($iv['job_title']) ?></div>
                                </div>
                            </div>
                            <span class="badge-stage stage-sky">📅 <?= e(format_datetime($iv['interview_at'])) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card widget-card flex-1">
            <div>
                <div class="widget-header flex-between">
                    <h3 class="widget-title">Application Success Rate</h3>
                    <span class="badge-stage stage-emerald"><?= $successRate ?>% Conversion</span>
                </div>
                <p class="widget-subtitle">Conversion from applied to selected offer.</p>
                <div class="stat-highlight">
                    <span class="stat-number"><?= $successRate ?>%</span>
                    <span class="stat-meta"><?= $hiredApplications ?> of <?= $totalApplications ?> applications hired</span>
                </div>
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" style="width: <?= min(100, $successRate) ?>%;"></div>
                </div>
            </div>
            <div class="widget-footer flex-between">
                <span>Active Applications: <strong><?= $pendingApplications + $shortlistedApplications ?></strong></span>
                <span>Profile Status: <strong><?= $hasResume ? 'Complete' : 'Pending Resume' ?></strong></span>
            </div>
        </div>
    </section>

</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('appSearchInput');
    const tableRows = document.querySelectorAll('#student-apps-table tbody tr');

    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }
});

function filterStudentTable() {
    const status = document.getElementById('statusFilter').value;

    const url = new URL(window.location);
    if (status && status !== 'all') {
        url.searchParams.set('status', status);
    } else {
        url.searchParams.delete('status');
    }
    window.history.pushState({}, '', url);

    const tableRows = document.querySelectorAll('#student-apps-table tbody tr');
    tableRows.forEach(row => {
        const rowStatus = row.getAttribute('data-status');
        if (status === 'all' || rowStatus === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
