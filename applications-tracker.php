<?php
/**
 * applications-tracker.php
 * Interactive Visual Pipeline & Stepper Tracker for Candidates.
 * Tracks application stages, interview countdowns, recruiter notes, and status timelines.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();
require_role('student');

$pdo = get_db();
ensure_meeting_link_column($pdo);
$studentId = (int) current_user_id();

// ---------------------------------------------------------------
// POST Handlers (Withdraw Application)
// ---------------------------------------------------------------
if (is_post()) {
    verify_csrf();
    $action = post('action');
    $applicationId = (int) ($_POST['application_id'] ?? 0);

    if ($action === 'withdraw') {
        $stmt = $pdo->prepare(
            'UPDATE applications SET status = "withdrawn"
             WHERE id = :id AND student_id = :student_id AND status != "hired"'
        );
        $stmt->execute([':id' => $applicationId, ':student_id' => $studentId]);

        flash(
            $stmt->rowCount() === 0
                ? 'Application not found, or it can no longer be withdrawn.'
                : 'Application withdrawn successfully.',
            $stmt->rowCount() === 0 ? 'error' : 'success'
        );
        redirect('applications-tracker.php');
    }

    redirect('applications-tracker.php');
}

// ---------------------------------------------------------------
// GET: Fetch Applications & Timeline Data
// ---------------------------------------------------------------
$filter = query('filter', 'all');

$stmt = $pdo->prepare(
    'SELECT a.id AS application_id, a.status, a.applied_at, a.interview_at, a.meeting_link, a.notes,
            j.id AS job_id, j.title AS job_title, j.job_type, j.work_mode, j.location, j.salary, j.deadline,
            c.company_name
     FROM applications a
     INNER JOIN jobs j ON a.job_id = j.id
     INNER JOIN companies c ON j.company_id = c.id
     WHERE a.student_id = :student_id
     ORDER BY a.applied_at DESC'
);
$stmt->execute([':student_id' => $studentId]);
$allApps = $stmt->fetchAll();

// Metrics
$totalSubmitted = count($allApps);
$inProgressCount = 0;
$interviewsCount = 0;
$offersCount = 0;

$filteredApps = [];

foreach ($allApps as $app) {
    $st = $app['status'];
    $hasInterview = !empty($app['interview_at']) && !in_array($st, ['rejected', 'withdrawn'], true);

    if (in_array($st, ['applied', 'under_review', 'shortlisted'], true)) {
        $inProgressCount++;
    }
    if ($hasInterview) {
        $interviewsCount++;
    }
    if ($st === 'hired') {
        $offersCount++;
    }

    // Filter Logic
    if ($filter === 'active' && !in_array($st, ['applied', 'under_review', 'shortlisted'], true)) continue;
    if ($filter === 'interviews' && !$hasInterview) continue;
    if ($filter === 'offers' && $st !== 'hired') continue;

    $filteredApps[] = $app;
}

$pageTitle = 'Visual Application Tracker';
$topbarTitle = 'Pipeline Tracker';
$activeNav = $filter === 'interviews' ? 'interviews' : 'tracker';
$layout = 'app';

require __DIR__ . '/header.php';
?>

<style>
    .tracker-container {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        width: 100%;
        max-width: 80rem;
        margin: 0 auto;
        padding: 0.5rem 0;
    }

    .tracker-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .tracker-title {
        font-size: 1.875rem;
        font-weight: 800;
        letter-spacing: -0.025em;
        color: var(--slate-900);
        margin: 0;
    }

    .tracker-subtitle {
        font-size: 0.875rem;
        color: var(--slate-500);
        margin-top: 0.25rem;
    }

    .metrics-grid-tracker {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.25rem;
    }

    .metric-card-tracker {
        background: #ffffff;
        border: 1px solid var(--slate-200);
        border-radius: 1rem;
        padding: 1.25rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }

    .metric-val-tracker {
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--slate-900);
    }

    .metric-lbl-tracker {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--slate-500);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-top: 0.25rem;
    }

    .filter-pills-tracker {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .pill-tracker {
        display: inline-flex;
        align-items: center;
        padding: 0.5rem 1rem;
        border-radius: 9999px;
        font-size: 0.8125rem;
        font-weight: 600;
        color: var(--slate-600);
        background: #ffffff;
        border: 1px solid var(--slate-200);
        text-decoration: none;
        transition: all 0.2s;
    }

    .pill-tracker:hover, .pill-tracker.active {
        background: var(--slate-900);
        color: #ffffff;
        border-color: var(--slate-900);
    }

    .tracker-card {
        background: #ffffff;
        border: 1px solid var(--slate-200);
        border-radius: 1rem;
        padding: 1.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }

    .tracker-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .company-avatar-md {
        width: 44px;
        height: 44px;
        border-radius: 0.75rem;
        background: var(--slate-900);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 0.9375rem;
        flex-shrink: 0;
    }

    /* Stepper Timeline Bar */
    .stepper-bar-container {
        position: relative;
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        margin: 1rem 0;
        padding: 0 0.5rem;
    }

    .stepper-bar-line {
        position: absolute;
        top: 15px;
        left: 10%;
        right: 10%;
        height: 3px;
        background: var(--slate-200);
        z-index: 1;
    }

    .stepper-bar-progress {
        position: absolute;
        top: 15px;
        left: 10%;
        height: 3px;
        background: var(--brand-600, #4f46e5);
        transition: width 0.4s ease;
        z-index: 2;
    }

    .stepper-step {
        position: relative;
        z-index: 3;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
    }

    .stepper-node {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #ffffff;
        border: 2px solid var(--slate-300);
        color: var(--slate-500);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.75rem;
        font-weight: 700;
        transition: all 0.2s;
    }

    .stepper-step.completed .stepper-node {
        background: var(--brand-600, #4f46e5);
        border-color: var(--brand-600, #4f46e5);
        color: #ffffff;
    }

    .stepper-step.active .stepper-node {
        background: #ffffff;
        border-color: var(--brand-600, #4f46e5);
        color: var(--brand-600, #4f46e5);
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15);
    }

    .stepper-step.hired .stepper-node {
        background: #10b981;
        border-color: #10b981;
        color: #ffffff;
    }

    .stepper-step.rejected .stepper-node {
        background: #f43f5e;
        border-color: #f43f5e;
        color: #ffffff;
    }

    .stepper-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--slate-700);
        margin-top: 0.5rem;
    }

    .stepper-subtext {
        font-size: 0.6875rem;
        color: var(--slate-400);
        margin-top: 0.125rem;
    }

    .interview-alert-box {
        background: #f0f9ff;
        border: 1px solid #bae6fd;
        border-radius: 0.75rem;
        padding: 0.875rem 1.125rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }

    .recruiter-notes-box {
        background: #f8fafc;
        border-left: 4px solid var(--brand-600, #4f46e5);
        border-radius: 0.5rem;
        padding: 0.875rem 1.125rem;
        font-size: 0.8125rem;
        color: var(--slate-700);
    }

    @media (max-width: 768px) {
        .metrics-grid-tracker { grid-template-columns: repeat(2, 1fr); }
        .stepper-bar-container { grid-template-columns: 1fr; gap: 1rem; }
        .stepper-bar-line, .stepper-bar-progress { display: none; }
        .stepper-step { flex-direction: row; gap: 0.75rem; text-align: left; }
    }
</style>

<main class="canvas">
    <div class="tracker-container">
        <!-- Page Header -->
        <div class="tracker-header">
            <div>
                <h1 class="tracker-title">Visual Application Tracker</h1>
                <p class="tracker-subtitle">Monitor live application stages, recruiter review progress, and scheduled technical interview rounds.</p>
            </div>
            <a href="jobs.php" class="pill-tracker active" style="padding: 0.625rem 1.25rem;">
                + Apply for New Jobs
            </a>
        </div>

        <!-- Metrics Overview -->
        <div class="metrics-grid-tracker">
            <div class="metric-card-tracker">
                <div class="metric-val-tracker"><?= $totalSubmitted ?></div>
                <div class="metric-lbl-tracker">Submitted Applications</div>
            </div>
            <div class="metric-card-tracker">
                <div class="metric-val-tracker" style="color: var(--brand-600);"><?= $inProgressCount ?></div>
                <div class="metric-lbl-tracker">In Screening / Review</div>
            </div>
            <div class="metric-card-tracker">
                <div class="metric-val-tracker" style="color: #0284c7;"><?= $interviewsCount ?></div>
                <div class="metric-lbl-tracker">Scheduled Interviews</div>
            </div>
            <div class="metric-card-tracker">
                <div class="metric-val-tracker" style="color: #059669;"><?= $offersCount ?></div>
                <div class="metric-lbl-tracker">Job Offers / Hired</div>
            </div>
        </div>

        <!-- Filter Sub-bar -->
        <div class="filter-pills-tracker">
            <a href="applications-tracker.php?filter=all" class="pill-tracker <?= $filter === 'all' ? 'active' : '' ?>">All Tracker Logs (<?= $totalSubmitted ?>)</a>
            <a href="applications-tracker.php?filter=active" class="pill-tracker <?= $filter === 'active' ? 'active' : '' ?>">In-Progress Only (<?= $inProgressCount ?>)</a>
            <a href="applications-tracker.php?filter=interviews" class="pill-tracker <?= $filter === 'interviews' ? 'active' : '' ?>">Scheduled Interviews (<?= $interviewsCount ?>)</a>
            <a href="applications-tracker.php?filter=offers" class="pill-tracker <?= $filter === 'offers' ? 'active' : '' ?>">Offers / Hired (<?= $offersCount ?>)</a>
        </div>

        <!-- Applications Timeline List -->
        <?php if (empty($filteredApps)): ?>
            <div class="metric-card-tracker" style="text-align: center; padding: 4rem 2rem;">
                <div style="width: 56px; height: 56px; margin: 0 auto 1rem auto; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #94a3b8;">
                    <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                </div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.5rem;">No Applications Found</h3>
                <p style="font-size: 0.875rem; color: var(--slate-500); max-width: 28rem; margin: 0 auto 1.5rem auto;">There are no applications matching your selected filter criteria.</p>
                <a href="jobs.php" class="pill-tracker active" style="padding: 0.625rem 1.25rem;">Explore Jobs &rarr;</a>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 1.25rem;">
                <?php foreach ($filteredApps as $app): 
                    $st = $app['status'];
                    $appIdStr = 'APP-' . str_pad((string)$app['application_id'], 6, '0', STR_PAD_LEFT);
                    $appliedDateStr = format_date($app['applied_at']);
                    
                    // Determine Stepper Progress Percentage & Step States
                    $stepProgress = 10; // Step 1 default (Applied)
                    $step1Class = 'completed';
                    $step2Class = '';
                    $step3Class = '';
                    $step4Class = '';

                    $step4Text = 'Final Decision';
                    $step4Sub = 'Offer / Result';

                    if (in_array($st, ['under_review', 'shortlisted', 'hired', 'rejected'], true)) {
                        $stepProgress = 40;
                        $step2Class = 'completed';
                    }
                    
                    if (in_array($st, ['shortlisted', 'hired'], true) || !empty($app['interview_at'])) {
                        $stepProgress = 70;
                        $step3Class = 'completed';
                    }

                    if ($st === 'hired') {
                        $stepProgress = 100;
                        $step4Class = 'hired completed';
                        $step4Text = 'Offer Accepted';
                        $step4Sub = 'Hired 🎉';
                    } elseif ($st === 'rejected') {
                        $stepProgress = 100;
                        $step4Class = 'rejected completed';
                        $step4Text = 'Not Selected';
                        $step4Sub = 'Review Closed';
                    } elseif ($st === 'withdrawn') {
                        $stepProgress = 100;
                        $step4Class = 'rejected completed';
                        $step4Text = 'Withdrawn';
                        $step4Sub = 'Cancelled';
                    }
                ?>
                    <div class="tracker-card">
                        <!-- Top Row: Role, Ref, Company -->
                        <div class="tracker-card-header">
                            <div style="display: flex; align-items: center; gap: 0.875rem;">
                                <div class="company-avatar-md">
                                    <?= e(initials($app['company_name'])) ?>
                                </div>
                                <div>
                                    <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                        <h3 style="font-size: 1.125rem; font-weight: 800; color: var(--slate-900); margin: 0;">
                                            <a href="jobs.php?id=<?= (int)$app['job_id'] ?>" style="color: inherit; text-decoration: none;">
                                                <?= e($app['job_title']) ?>
                                            </a>
                                        </h3>
                                        <span style="font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.5rem; background: var(--slate-100); border-radius: 0.375rem; color: var(--slate-600);"><?= e($appIdStr) ?></span>
                                    </div>
                                    <div style="font-size: 0.8125rem; color: var(--slate-500); margin-top: 0.25rem;">
                                        <strong><?= e($app['company_name']) ?></strong> • <?= e(ucfirst($app['job_type'])) ?> • <?= e(ucfirst($app['work_mode'])) ?> (<?= e($app['location'] ?: 'Remote') ?>)
                                    </div>
                                </div>
                            </div>

                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <a href="jobs.php?id=<?= (int)$app['job_id'] ?>" class="pill-tracker" style="font-size: 0.75rem; padding: 0.375rem 0.75rem;">
                                    View Job Details &rarr;
                                </a>
                                <?php if (in_array($st, ['applied', 'under_review', 'shortlisted'], true)): ?>
                                    <form method="POST" action="applications-tracker.php" onsubmit="return confirmWithdraw(event, '<?= e(addslashes($app['job_title'])) ?>');" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="withdraw">
                                        <input type="hidden" name="application_id" value="<?= (int)$app['application_id'] ?>">
                                        <button type="submit" class="pill-tracker" style="font-size: 0.75rem; padding: 0.375rem 0.75rem; color: #be123c; background: #fff1f2; border-color: #fecdd3;">
                                            Withdraw
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Visual Stepper Progress Bar -->
                        <div class="stepper-bar-container">
                            <div class="stepper-bar-line"></div>
                            <div class="stepper-bar-progress" style="width: <?= $stepProgress ?>%;"></div>

                            <!-- Step 1 -->
                            <div class="stepper-step <?= $step1Class ?>">
                                <div class="stepper-node">✓</div>
                                <div class="stepper-label">Application Submitted</div>
                                <div class="stepper-subtext"><?= e($appliedDateStr) ?></div>
                            </div>

                            <!-- Step 2 -->
                            <div class="stepper-step <?= $step2Class ?>">
                                <div class="stepper-node"><?= $step2Class ? '✓' : '2' ?></div>
                                <div class="stepper-label">Recruiter Review</div>
                                <div class="stepper-subtext"><?= in_array($st, ['under_review', 'shortlisted', 'hired'], true) ? 'In Progress / Passed' : 'Pending' ?></div>
                            </div>

                            <!-- Step 3 -->
                            <div class="stepper-step <?= $step3Class ?>">
                                <div class="stepper-node"><?= $step3Class ? '✓' : '3' ?></div>
                                <div class="stepper-label">Technical Round / Interview</div>
                                <div class="stepper-subtext">
                                    <?php 
                                        if (!empty($app['interview_at'])) {
                                            echo (strtotime($app['interview_at']) < time()) ? 'Interview Held / Passed' : 'Scheduled';
                                        } else {
                                            echo 'Awaiting Slot';
                                        }
                                    ?>
                                </div>
                            </div>

                            <!-- Step 4 -->
                            <div class="stepper-step <?= $step4Class ?>">
                                <div class="stepper-node"><?= ($st === 'hired' ? '★' : ($st === 'rejected' ? '✕' : '4')) ?></div>
                                <div class="stepper-label"><?= e($step4Text) ?></div>
                                <div class="stepper-subtext"><?= e($step4Sub) ?></div>
                            </div>
                        </div>

                        <!-- Scheduled Interview Alert Widget (If Active) -->
                        <?php if (!empty($app['interview_at']) && !in_array($st, ['rejected', 'withdrawn'], true)): 
                            $intTs = strtotime($app['interview_at']);
                            $nowTs = time();
                            $isPassed = ($intTs < $nowTs);

                            $intDateStr = date('Y-m-d', $intTs);
                            $todayDateStr = date('Y-m-d', $nowTs);
                            $tomorrowDateStr = date('Y-m-d', strtotime('+1 day', $nowTs));

                            if ($isPassed) {
                                $interviewStatusText = 'Passed';
                                $interviewBoxTitle = 'Interview Round Passed / Held';
                                $interviewBoxStyle = 'background: #f8fafc; border: 1px solid #e2e8f0;';
                                $interviewTitleColor = '#475569';
                                $interviewTextColor = '#64748b';
                                $interviewBadgeBg = '#64748b';
                            } else {
                                $interviewBoxTitle = 'Interview Round Scheduled';
                                $interviewBoxStyle = 'background: #f0f9ff; border: 1px solid #bae6fd;';
                                $interviewTitleColor = '#0369a1';
                                $interviewTextColor = '#0284c7';
                                $interviewBadgeBg = '#0284c7';

                                if ($intDateStr === $todayDateStr) {
                                    $interviewStatusText = 'Today!';
                                } elseif ($intDateStr === $tomorrowDateStr) {
                                    $interviewStatusText = 'Tomorrow';
                                } else {
                                    $dStart = new DateTime($todayDateStr);
                                    $dEnd = new DateTime($intDateStr);
                                    $diffDays = (int) $dStart->diff($dEnd)->format('%r%a');
                                    $interviewStatusText = "In {$diffDays} days";
                                }
                            }
                        ?>
                            <div class="interview-alert-box" style="<?= $interviewBoxStyle ?>">
                                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                                    <div style="font-size: 1.5rem;">📅</div>
                                    <div>
                                        <div style="font-weight: 700; font-size: 0.875rem; color: <?= $interviewTitleColor ?>;"><?= e($interviewBoxTitle) ?></div>
                                        <div style="font-size: 0.8125rem; color: <?= $interviewTextColor ?>; margin-top: 0.125rem;">
                                            <?= $isPassed ? 'Held on' : 'Scheduled for' ?> <strong><?= e(format_datetime($app['interview_at'])) ?></strong>
                                            <?= $isPassed ? ' <span style="font-size: 0.75rem; opacity: 0.85;">(Awaiting recruiter update)</span>' : '' ?>
                                        </div>
                                    </div>
                                </div>
                                <div style="display: flex; align-items: center; gap: 0.625rem; flex-wrap: wrap;">
                                    <?php if (!empty($app['meeting_link'])): ?>
                                        <?php if ($isPassed): ?>
                                            <a href="<?= e(safe_url($app['meeting_link'])) ?>" target="_blank" rel="noopener" class="pill-tracker" style="background: #f1f5f9; border-color: #cbd5e1; color: #64748b; font-weight: 600; display: inline-flex; align-items: center; gap: 0.375rem; text-decoration: none; padding: 0.4rem 0.875rem; font-size: 0.8125rem;">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                Meeting Link (Passed)
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= e(safe_url($app['meeting_link'])) ?>" target="_blank" rel="noopener" class="pill-tracker active" style="background: #0284c7; border-color: #0284c7; color: #ffffff; font-weight: 700; display: inline-flex; align-items: center; gap: 0.375rem; text-decoration: none; padding: 0.4rem 0.875rem; font-size: 0.8125rem;">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                Join Meeting &rarr;
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <div style="padding: 0.375rem 0.875rem; background: <?= $interviewBadgeBg ?>; color: #ffffff; border-radius: 9999px; font-size: 0.75rem; font-weight: 700;">
                                        <?= e($interviewStatusText) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Recruiter Notes / Instructions -->
                        <?php if (!empty($app['notes'])): ?>
                            <div class="recruiter-notes-box">
                                <div style="font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem;">💬 Note from Hiring Team:</div>
                                <div><?= e($app['notes']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<script>
function confirmWithdraw(event, jobTitle) {
    event.preventDefault();
    const form = event.target;

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Withdraw Application?',
            text: `Are you sure you want to withdraw your application for "${jobTitle}"? This action cannot be undone.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Yes, Withdraw',
            cancelButtonText: 'Keep Application',
            borderRadius: '1rem'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    } else {
        if (confirm(`Are you sure you want to withdraw your application for "${jobTitle}"?`)) {
            form.submit();
        }
    }
    return false;
}
</script>

<?php require __DIR__ . '/footer.php'; ?>
