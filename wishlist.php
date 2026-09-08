<?php
/**
 * wishlist.php
 * Saved Jobs & Wishlist Center for Candidate Users.
 * Displays saved job opportunities in a modern card grid view with application actions.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();
require_role('student');

$pdo = get_db();
$studentId = (int) current_user_id();

// ---------------------------------------------------------------
// POST Handlers (Unsave & Apply)
// ---------------------------------------------------------------
if (is_post()) {
    verify_csrf();
    $action = post('action');
    $jobId = (int) ($_POST['job_id'] ?? 0);

    if ($action === 'unsave') {
        $stmt = $pdo->prepare('DELETE FROM saved_jobs WHERE student_id = :uid AND job_id = :job');
        $stmt->execute([':uid' => $studentId, ':job' => $jobId]);
        flash('Job removed from your saved wishlist.');
        redirect('wishlist.php');
    }

    if ($action === 'apply') {
        // Check job validity
        $stmt = $pdo->prepare('SELECT id, status, deadline FROM jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $jobId]);
        $job = $stmt->fetch();

        if (!$job) {
            flash('Job opportunity not found.', 'error');
            redirect('wishlist.php');
        }

        if ($job['status'] !== 'open') {
            flash('This job position is currently closed.', 'error');
            redirect('wishlist.php');
        }

        if (!empty($job['deadline']) && strtotime($job['deadline']) < strtotime(date('Y-m-d'))) {
            flash('The application deadline for this position has passed.', 'error');
            redirect('wishlist.php');
        }

        // Resume check
        $stmt = $pdo->prepare('SELECT resume_path FROM student_profiles WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $studentId]);
        $prof = $stmt->fetch();

        if (!$prof || empty($prof['resume_path'])) {
            flash('Please upload a resume on your profile page before submitting applications.', 'error');
            redirect('profile.php');
        }

        // Existing application check
        $stmt = $pdo->prepare('SELECT id FROM applications WHERE job_id = :job_id AND student_id = :student_id LIMIT 1');
        $stmt->execute([':job_id' => $jobId, ':student_id' => $studentId]);
        
        if ($stmt->fetch()) {
            flash('You have already applied for this job opportunity.', 'error');
            redirect('wishlist.php');
        }

        // Insert Application
        $stmt = $pdo->prepare(
            'INSERT INTO applications (job_id, student_id, status, applied_at)
             VALUES (:job_id, :student_id, "applied", NOW())'
        );
        $stmt->execute([':job_id' => $jobId, ':student_id' => $studentId]);
        $appId = (int) $pdo->lastInsertId();

        // Trigger Notification
        require_once __DIR__ . '/notifications.php';
        notify_application_submitted($pdo, $appId);

        flash('Application submitted successfully!');
        redirect('wishlist.php');
    }

    redirect('wishlist.php');
}

// ---------------------------------------------------------------
// GET: Fetch Saved Jobs List
// ---------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT 
        s.job_id,
        s.created_at AS saved_at,
        j.id,
        j.title,
        j.description,
        j.requirements,
        j.skills_required,
        j.salary,
        j.location,
        j.job_type,
        j.work_mode,
        j.status AS job_status,
        j.deadline,
        j.created_at AS posted_at,
        c.company_name,
        a.id AS application_id,
        a.status AS application_status
     FROM saved_jobs s
     INNER JOIN jobs j ON s.job_id = j.id
     INNER JOIN companies c ON j.company_id = c.id
     LEFT JOIN applications a ON (a.job_id = j.id AND a.student_id = :uid_app)
     WHERE s.student_id = :uid_saved
     ORDER BY s.created_at DESC'
);
$stmt->execute([':uid_app' => $studentId, ':uid_saved' => $studentId]);
$savedItems = $stmt->fetchAll();

// Metrics
$totalSaved = count($savedItems);
$openPositionsCount = 0;
$appliedCount = 0;

foreach ($savedItems as $item) {
    if ($item['job_status'] === 'open') {
        $openPositionsCount++;
    }
    if (!empty($item['application_id']) && $item['application_status'] !== 'withdrawn') {
        $appliedCount++;
    }
}

$pageTitle = 'Saved Jobs & Wishlist';
$topbarTitle = 'Saved Opportunities';
$activeNav = 'saved';
$layout = 'app';

require __DIR__ . '/header.php';
?>

<style>
    .wishlist-container {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        width: 100%;
        max-width: 80rem;
        margin: 0 auto;
        padding: 0.5rem 0;
    }

    .wishlist-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .wishlist-title {
        font-size: 1.875rem;
        font-weight: 800;
        letter-spacing: -0.025em;
        color: var(--slate-900);
        margin: 0;
    }

    .wishlist-subtitle {
        font-size: 0.875rem;
        color: var(--slate-500);
        margin-top: 0.25rem;
    }

    .metrics-grid-wishlist {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1.25rem;
    }

    .metric-card-wishlist {
        background: #ffffff;
        border: 1px solid var(--slate-200);
        border-radius: 1rem;
        padding: 1.25rem;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }

    .metric-val-wishlist {
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--slate-900);
    }

    .metric-lbl-wishlist {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--slate-500);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-top: 0.25rem;
    }

    .wishlist-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 1.25rem;
    }

    .job-card-wishlist {
        background: #ffffff;
        border: 1px solid var(--slate-200);
        border-radius: 1rem;
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        transition: all 0.2s ease-in-out;
        position: relative;
    }

    .job-card-wishlist:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(15,23,42,0.08);
        border-color: var(--brand-400, #818cf8);
    }

    .company-avatar-lg {
        width: 42px;
        height: 42px;
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

    .badge-pill {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.625rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: capitalize;
    }

    .badge-pill-indigo { background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; }
    .badge-pill-sky { background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; }
    .badge-pill-emerald { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
    .badge-pill-rose { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; }
    .badge-pill-slate { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

    .skills-tag-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 0.375rem;
        margin: 1rem 0;
    }

    .skill-chip {
        padding: 0.2rem 0.5rem;
        background: var(--slate-100);
        border-radius: 0.375rem;
        font-size: 0.75rem;
        font-weight: 500;
        color: var(--slate-700);
    }

    .card-footer-actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-top: 1.25rem;
        padding-top: 1rem;
        border-top: 1px solid var(--slate-100);
    }

    .btn-wishlist {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        padding: 0.5rem 1rem;
        border-radius: 0.625rem;
        font-size: 0.8125rem;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.15s;
        border: none;
        cursor: pointer;
    }

    .btn-wishlist-primary {
        background: var(--slate-900);
        color: #ffffff;
    }
    .btn-wishlist-primary:hover {
        background: #1e293b;
    }

    .btn-wishlist-secondary {
        background: var(--slate-100);
        color: var(--slate-700);
    }
    .btn-wishlist-secondary:hover {
        background: var(--slate-200);
        color: var(--slate-900);
    }

    .btn-wishlist-danger {
        background: #fff1f2;
        color: #be123c;
        border: 1px solid #fecdd3;
    }
    .btn-wishlist-danger:hover {
        background: #ffe4e6;
    }

    @media (max-width: 768px) {
        .metrics-grid-wishlist { grid-template-columns: 1fr; }
        .wishlist-grid { grid-template-columns: 1fr; }
    }
</style>

<main class="canvas">
    <div class="wishlist-container">
        <!-- Page Header -->
        <div class="wishlist-header">
            <div>
                <h1 class="wishlist-title">Saved Wishlist Opportunities</h1>
                <p class="wishlist-subtitle">Manage your bookmarked job postings, review requirements, and submit applications directly.</p>
            </div>
            <a href="jobs.php" class="btn-wishlist btn-wishlist-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                Browse All Open Jobs
            </a>
        </div>

        <!-- Metrics Overview -->
        <div class="metrics-grid-wishlist">
            <div class="metric-card-wishlist">
                <div class="metric-val-wishlist" style="color: var(--brand-600);"><?= $totalSaved ?></div>
                <div class="metric-lbl-wishlist">Total Saved Items</div>
            </div>
            <div class="metric-card-wishlist">
                <div class="metric-val-wishlist" style="color: var(--emerald-600);"><?= $openPositionsCount ?></div>
                <div class="metric-lbl-wishlist">Active Hiring Positions</div>
            </div>
            <div class="metric-card-wishlist">
                <div class="metric-val-wishlist" style="color: #6366f1;"><?= $appliedCount ?></div>
                <div class="metric-lbl-wishlist">Submitted Applications</div>
            </div>
        </div>

        <!-- Saved Job Cards Grid -->
        <?php if (empty($savedItems)): ?>
            <div class="metric-card-wishlist" style="text-align: center; padding: 4rem 2rem;">
                <div style="width: 56px; height: 56px; margin: 0 auto 1rem auto; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #94a3b8;">
                    <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path></svg>
                </div>
                <h3 style="font-size: 1.125rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.5rem;">Your Wishlist is Empty</h3>
                <p style="font-size: 0.875rem; color: var(--slate-500); max-width: 28rem; margin: 0 auto 1.5rem auto;">You haven't saved any job postings yet. Bookmark positions while browsing jobs to track and review them here.</p>
                <a href="jobs.php" class="btn-wishlist btn-wishlist-primary" style="padding: 0.625rem 1.25rem;">+ Explore Job Board</a>
            </div>
        <?php else: ?>
            <div class="wishlist-grid">
                <?php foreach ($savedItems as $item): ?>
                    <div class="job-card-wishlist">
                        <div>
                            <!-- Card Header: Company Logo & Unsave Icon -->
                            <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; margin-bottom: 1rem;">
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <div class="company-avatar-lg">
                                        <?= e(initials($item['company_name'])) ?>
                                    </div>
                                    <div>
                                        <div style="font-size: 0.8125rem; font-weight: 700; color: var(--slate-700);"><?= e($item['company_name']) ?></div>
                                        <div style="font-size: 0.75rem; color: var(--slate-400);">Saved <?= e(format_date($item['saved_at'])) ?></div>
                                    </div>
                                </div>

                                <form method="POST" action="wishlist.php" style="margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="unsave">
                                    <input type="hidden" name="job_id" value="<?= (int)$item['id'] ?>">
                                    <button type="submit" class="btn-wishlist-danger" style="padding: 0.35rem 0.5rem; border-radius: 0.5rem;" title="Remove from Wishlist">
                                        <svg width="15" height="15" fill="currentColor" viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                                    </button>
                                </form>
                            </div>

                            <!-- Job Title & Badges -->
                            <h3 style="font-size: 1.0625rem; font-weight: 800; color: var(--slate-900); margin: 0 0 0.5rem 0; line-height: 1.3;">
                                <a href="jobs.php?id=<?= (int)$item['id'] ?>" style="color: inherit; text-decoration: none;">
                                    <?= e($item['title']) ?>
                                </a>
                            </h3>

                            <div style="display: flex; flex-wrap: wrap; gap: 0.375rem; margin-bottom: 0.75rem;">
                                <span class="badge-pill badge-pill-indigo"><?= e(ucfirst($item['job_type'])) ?></span>
                                <span class="badge-pill badge-pill-sky"><?= e(ucfirst($item['work_mode'])) ?></span>
                                <?php if ($item['job_status'] === 'open'): ?>
                                    <span class="badge-pill badge-pill-emerald">● Hiring Open</span>
                                <?php else: ?>
                                    <span class="badge-pill badge-pill-rose">● Closed</span>
                                <?php endif; ?>
                            </div>

                            <!-- Job Meta Info -->
                            <div style="font-size: 0.8125rem; color: var(--slate-600); display: flex; flex-direction: column; gap: 0.25rem;">
                                <div>📍 <strong>Location:</strong> <?= e($item['location'] ?: 'Remote / Hybrid') ?></div>
                                <div>💰 <strong>Compensation:</strong> <?= e($item['salary'] ?: 'Competitive Market Rate') ?></div>
                            </div>

                            <!-- Required Skills Chips -->
                            <?php if (!empty($item['skills_required'])): ?>
                                <div class="skills-tag-bar">
                                    <?php 
                                        $skillsList = array_slice(array_map('trim', explode(',', $item['skills_required'])), 0, 4);
                                        foreach ($skillsList as $sk): 
                                    ?>
                                        <span class="skill-chip"><?= e($sk) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Card Footer -->
                        <div class="card-footer-actions">
                            <a href="jobs.php?id=<?= (int)$item['id'] ?>" class="btn-wishlist btn-wishlist-secondary">
                                Details &rarr;
                            </a>

                            <?php if (!empty($item['application_id']) && $item['application_status'] !== 'withdrawn'): ?>
                                <span class="badge-pill badge-pill-emerald" style="padding: 0.5rem 0.75rem; font-size: 0.75rem;">
                                    ✓ <?= e(status_label($item['application_status'])) ?>
                                </span>
                            <?php elseif ($item['job_status'] === 'open'): ?>
                                <form method="POST" action="wishlist.php" style="margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="apply">
                                    <input type="hidden" name="job_id" value="<?= (int)$item['id'] ?>">
                                    <button type="submit" class="btn-wishlist btn-wishlist-primary">
                                        Apply Now
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="badge-pill badge-pill-slate">Position Closed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>
