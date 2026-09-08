<?php
/**
 * profile.php
 * Role-aware profile page with modern recruiter & student UI layout.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();

$pdo = get_db();
ensure_profile_image_column($pdo);
$userId = (int) current_user_id();
$role = (string) current_user_role();

// ---------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------
if (is_post()) {
    verify_csrf();
    $which = post('form');

    // ---- Profile Picture Upload (both roles) ----
    if ($which === 'avatar') {
        if (!isset($_FILES['avatar_image'])) {
            flash('No image file provided.', 'error');
            redirect('profile.php');
        }

        $uploadError = validate_avatar_upload($_FILES['avatar_image']);
        if ($uploadError !== '') {
            flash($uploadError, 'error');
            redirect('profile.php');
        }

        $ext = strtolower(pathinfo((string) $_FILES['avatar_image']['name'], PATHINFO_EXTENSION));
        $newFilename = secure_random_filename($ext);

        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
        }

        if (!move_uploaded_file($_FILES['avatar_image']['tmp_name'], UPLOAD_DIR . $newFilename)) {
            flash('Failed to save the uploaded image.', 'error');
            redirect('profile.php');
        }

        // Delete old profile picture if exists
        $stmt = $pdo->prepare('SELECT profile_image FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $old = $stmt->fetchColumn();
        if ($old && is_file(UPLOAD_DIR . basename((string)$old))) {
            unlink(UPLOAD_DIR . basename((string)$old));
        }

        $stmt = $pdo->prepare('UPDATE users SET profile_image = :img WHERE id = :id');
        $stmt->execute([':img' => $newFilename, ':id' => $userId]);

        $_SESSION['profile_image'] = $newFilename;
        flash('Profile picture updated successfully.');
        redirect('profile.php');
    }

    // ---- Remove Profile Picture ----
    if ($which === 'remove_avatar') {
        $stmt = $pdo->prepare('SELECT profile_image FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $old = $stmt->fetchColumn();
        if ($old && is_file(UPLOAD_DIR . basename((string)$old))) {
            unlink(UPLOAD_DIR . basename((string)$old));
        }

        $stmt = $pdo->prepare('UPDATE users SET profile_image = NULL WHERE id = :id');
        $stmt->execute([':id' => $userId]);

        $_SESSION['profile_image'] = null;
        flash('Profile picture removed.');
        redirect('profile.php');
    }

    // ---- Account info (both roles) ----
    if ($which === 'account') {
        $fullName = post('fullName') !== '' ? post('fullName') : post('full_name');
        $phone = post('phoneNumber') !== '' ? post('phoneNumber') : post('phone');
        $designation = post('roleDesignation') !== '' ? post('roleDesignation') : post('designation');

        if ($fullName === '' || strlen($fullName) > 150) {
            flash('Please enter a valid full name (max 150 characters).', 'error');
            redirect('profile.php');
        }

        $stmt = $pdo->prepare('UPDATE users SET full_name = :name, phone = :phone, designation = :designation WHERE id = :id');
        $stmt->execute([
            ':name' => $fullName,
            ':phone' => $phone !== '' ? $phone : null,
            ':designation' => $designation !== '' ? $designation : null,
            ':id' => $userId
        ]);

        $_SESSION['full_name'] = $fullName;
        flash('Account info saved successfully.');
        redirect('profile.php');
    }

    // ---- Student profile ----
    if ($which === 'student' && $role === 'student') {
        $stmt = $pdo->prepare(
            'UPDATE student_profiles SET education = :education, skills = :skills, bio = :bio
             WHERE user_id = :uid'
        );
        $stmt->execute([
            ':education' => post('education'),
            ':skills' => post('skills'),
            ':bio' => post('bio'),
            ':uid' => $userId,
        ]);

        flash('Student profile saved successfully.');
        redirect('profile.php');
    }

    // ---- Company profile ----
    if ($which === 'company' && $role === 'recruiter') {
        $companyName = post('companyName') !== '' ? post('companyName') : post('company_name');
        $website = post('website');
        $location = post('location');
        $description = post('description');
        $industry = post('industry');
        $companySize = post('company_size');

        if ($companyName === '') {
            flash('Company name cannot be empty.', 'error');
            redirect('profile.php');
        }
        if ($website !== '' && !preg_match('#^https?://#i', $website)) {
            flash('Website must start with http:// or https://.', 'error');
            redirect('profile.php');
        }

        $stmt = $pdo->prepare(
            'UPDATE companies SET company_name = :name, description = :description,
                                  website = :website, location = :location,
                                  industry = :industry, company_size = :company_size
             WHERE user_id = :uid'
        );
        $stmt->execute([
            ':name' => $companyName,
            ':description' => $description,
            ':website' => $website,
            ':location' => $location,
            ':industry' => $industry !== '' ? $industry : null,
            ':company_size' => $companySize !== '' ? $companySize : null,
            ':uid' => $userId,
        ]);

        flash('Company profile saved successfully.');
        redirect('profile.php');
    }

    // ---- Resume upload (students only) ----
    if ($which === 'resume' && $role === 'student') {
        if (!isset($_FILES['resume'])) {
            flash('No resume file provided.', 'error');
            redirect('profile.php');
        }

        $uploadError = validate_resume_upload($_FILES['resume']);
        if ($uploadError !== '') {
            flash($uploadError, 'error');
            redirect('profile.php');
        }

        $originalName = basename((string) $_FILES['resume']['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $newFilename = secure_random_filename($ext);

        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
        }

        if (!move_uploaded_file($_FILES['resume']['tmp_name'], UPLOAD_DIR . $newFilename)) {
            flash('Failed to save the uploaded file.', 'error');
            redirect('profile.php');
        }

        // Delete previous resume
        $stmt = $pdo->prepare('SELECT resume_path FROM student_profiles WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $old = $stmt->fetch();
        if ($old && !empty($old['resume_path'])) {
            $oldPath = UPLOAD_DIR . basename($old['resume_path']);
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
        }

        $stmt = $pdo->prepare(
            'UPDATE student_profiles SET resume_path = :path, resume_original_name = :name
             WHERE user_id = :uid'
        );
        $stmt->execute([':path' => $newFilename, ':name' => $originalName, ':uid' => $userId]);

        flash('Resume uploaded successfully.');
        redirect('profile.php');
    }

    // ---- Password change (both roles) ----
    if ($which === 'password') {
        $currentPassword = (string) (post('currentPassword') ?: post('current_password'));
        $newPassword = (string) (post('newPassword') ?: post('new_password'));

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            flash('Current password is incorrect.', 'error');
            redirect('profile.php');
        }
        if (!is_valid_password($newPassword)) {
            flash('New password must be at least 8 characters and include a letter and a number.', 'error');
            redirect('profile.php');
        }

        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);

        flash('Password updated successfully.');
        redirect('profile.php');
    }

    redirect('profile.php');
}

// ---------------------------------------------------------------
// GET: Load data
// ---------------------------------------------------------------
$stmt = $pdo->prepare('SELECT full_name, email, phone, designation, role, profile_image FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch() ?: [];
$_SESSION['profile_image'] = $user['profile_image'] ?? null;

$studentProfile = [];
$company = [];
$stats = [
    'active_jobs' => 0,
    'applicants' => 0,
    'interviews' => 0
];
$profileStrength = 10; // base score

if ($role === 'student') {
    $stmt = $pdo->prepare(
        'SELECT education, skills, bio, resume_path, resume_original_name
         FROM student_profiles WHERE user_id = :uid LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $studentProfile = $stmt->fetch() ?: [];

    // Calculate Student Stats
    $s1 = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE student_id = :uid');
    $s1->execute([':uid' => $userId]);
    $stats['active_jobs'] = (int) $s1->fetchColumn(); // Applied Jobs

    $s2 = $pdo->prepare('SELECT COUNT(*) FROM saved_jobs WHERE student_id = :uid');
    $s2->execute([':uid' => $userId]);
    $stats['applicants'] = (int) $s2->fetchColumn(); // Saved Jobs

    $s3 = $pdo->prepare('SELECT COUNT(*) FROM applications WHERE student_id = :uid AND interview_at IS NOT NULL');
    $s3->execute([':uid' => $userId]);
    $stats['interviews'] = (int) $s3->fetchColumn(); // Interviews

    // Calculate Student Profile Strength
    if (!empty($user['full_name'])) $profileStrength += 15;
    if (!empty($user['phone'])) $profileStrength += 15;
    if (!empty($user['profile_image'])) $profileStrength += 10;
    if (!empty($studentProfile['education'])) $profileStrength += 15;
    if (!empty($studentProfile['skills'])) $profileStrength += 15;
    if (!empty($studentProfile['bio'])) $profileStrength += 10;
    if (!empty($studentProfile['resume_path'])) $profileStrength += 10;
} else {
    $company = get_owned_company($pdo, $userId);
    $companyId = (int) ($company['id'] ?? 0);

    if ($companyId > 0) {
        $c1 = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE company_id = :cid AND status = 'open'");
        $c1->execute([':cid' => $companyId]);
        $stats['active_jobs'] = (int) $c1->fetchColumn();

        $c2 = $pdo->prepare("SELECT COUNT(a.id) FROM applications a JOIN jobs j ON a.job_id = j.id WHERE j.company_id = :cid");
        $c2->execute([':cid' => $companyId]);
        $stats['applicants'] = (int) $c2->fetchColumn();

        $c3 = $pdo->prepare("SELECT COUNT(a.id) FROM applications a JOIN jobs j ON a.job_id = j.id WHERE j.company_id = :cid AND a.interview_at IS NOT NULL");
        $c3->execute([':cid' => $companyId]);
        $stats['interviews'] = (int) $c3->fetchColumn();
    }

    // Calculate Recruiter Profile Strength
    if (!empty($user['full_name'])) $profileStrength += 15;
    if (!empty($user['phone'])) $profileStrength += 15;
    if (!empty($user['profile_image'])) $profileStrength += 10;
    if (!empty($user['designation'])) $profileStrength += 10;
    if (!empty($company['company_name'])) $profileStrength += 15;
    if (!empty($company['description'])) $profileStrength += 10;
    if (!empty($company['website'])) $profileStrength += 10;
    if (!empty($company['location'])) $profileStrength += 5;
}

$pageTitle = 'My Profile & Settings';
$topbarTitle = 'My Profile';
$activeNav = 'profile';
$layout = 'app';

require __DIR__ . '/header.php';
?>

<style>
    /* Scoped modern profile styles based on provided design */
    :root {
        --brand-50: #eef2ff;
        --brand-100: #e0e7ff;
        --brand-400: #818cf8;
        --brand-500: #6366f1;
        --brand-600: #4f46e5;
        --brand-700: #4338ca;
        --slate-50: #f8fafc;
        --slate-100: #f1f5f9;
        --slate-200: #e2e8f0;
        --slate-300: #cbd5e1;
        --slate-400: #94a3b8;
        --slate-500: #64748b;
        --slate-600: #475569;
        --slate-700: #334155;
        --slate-800: #1e293b;
        --slate-900: #0f172a;
        --emerald-50: #ecfdf5;
        --emerald-100: #d1fae5;
        --emerald-500: #10b981;
        --emerald-600: #059669;
        --emerald-700: #047857;
        --emerald-800: #065f46;
        --rose-50: #fff1f2;
        --rose-500: #f43f5e;
        --rose-600: #e11d48;
    }

    .profile-container {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
        width: 100%;
        max-width: 80rem;
        margin: 0 auto;
        padding: 0.5rem 0;
    }

    .page-header-prof {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .page-title-prof {
        font-size: 1.875rem;
        font-weight: 800;
        letter-spacing: -0.025em;
        color: var(--slate-900);
    }

    .page-subtitle-prof {
        font-size: 0.875rem;
        color: var(--slate-500);
        margin-top: 0.25rem;
    }

    .pill-badge-prof {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        background-color: var(--emerald-50);
        color: var(--emerald-700);
        border: 1px solid var(--emerald-100);
    }

    .card-prof {
        background-color: #ffffff;
        border-radius: 1rem;
        padding: 1.5rem;
        border: 1px solid var(--slate-200);
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    }

    .hero-avatar-prof {
        width: 72px;
        height: 72px;
        border-radius: 1rem;
        background: linear-gradient(135deg, var(--brand-600), #4338ca);
        color: #ffffff;
        font-size: 1.5rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.2);
        position: relative;
    }

    .stat-box-prof {
        flex: 1;
        text-align: center;
        padding: 0.75rem 1rem;
        border-radius: 0.75rem;
        border: 1px solid var(--slate-100);
        background-color: var(--slate-50);
    }
    .stat-box-prof.brand {
        background-color: rgba(238, 242, 255, 0.6);
        border-color: var(--brand-100);
    }
    .stat-box-prof.emerald {
        background-color: var(--emerald-50);
        border-color: var(--emerald-100);
    }

    .stat-num-prof {
        display: block;
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--slate-900);
    }
    .stat-box-prof.brand .stat-num-prof { color: var(--brand-700); }
    .stat-box-prof.emerald .stat-num-prof { color: var(--emerald-700); }

    .stat-label-prof {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--slate-500);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .nav-tabs-prof {
        display: flex;
        gap: 2rem;
        border-bottom: 1px solid var(--slate-200);
    }
    .tab-item-prof {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.75rem 0.25rem;
        border-bottom: 2px solid transparent;
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--slate-500);
        transition: all 0.2s;
        text-decoration: none;
    }
    .tab-item-prof:hover {
        color: var(--slate-700);
        border-color: var(--slate-300);
    }
    .tab-item-prof.active {
        border-color: var(--brand-600);
        color: var(--brand-600);
        font-weight: 600;
    }

    .grid-2-prof {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1.5rem;
    }
    .grid-2-col-prof {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }

    .form-group-prof {
        display: flex;
        flex-direction: column;
        gap: 0.375rem;
        margin-bottom: 1rem;
    }
    .form-label-prof {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--slate-700);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .input-control-prof {
        width: 100%;
        border-radius: 0.75rem;
        border: 1px solid var(--slate-200);
        padding: 0.625rem 0.875rem;
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--slate-800);
        background-color: #ffffff;
        transition: all 0.2s;
    }
    .input-control-prof:focus {
        outline: none;
        border-color: var(--brand-600);
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
    }
    .input-control-prof[readonly] {
        background-color: rgba(241, 245, 249, 0.7);
        color: var(--slate-600);
        cursor: not-allowed;
    }

    .btn-primary-prof {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.625rem 1.5rem;
        border-radius: 0.75rem;
        font-size: 0.875rem;
        font-weight: 600;
        color: #ffffff;
        background-color: var(--brand-600);
        border: none;
        cursor: pointer;
        box-shadow: 0 1px 2px rgba(79, 70, 229, 0.25);
        transition: all 0.15s;
    }
    .btn-primary-prof:hover {
        background-color: var(--brand-700);
    }

    @media (max-width: 1024px) {
        .grid-2-prof { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
        .grid-2-col-prof { grid-template-columns: 1fr; }
    }
</style>

<main class="canvas">
    <div class="profile-container">
        <!-- Page Header -->
        <div class="page-header-prof">
            <div>
                <h1 class="page-title-prof">My Profile</h1>
                <p class="page-subtitle-prof">
                    <?= $role === 'recruiter' 
                        ? 'Manage your recruiter identity, company brand presence, and account security.' 
                        : 'Manage your personal profile, skills, education, and resume settings.' ?>
                </p>
            </div>
            <span class="pill-badge-prof">
                <span style="width: 6px; height: 6px; border-radius: 50%; background: var(--emerald-500); margin-right: 6px;"></span>
                Profile Active • <?= $role === 'recruiter' ? e($company['company_name'] ?? 'Recruiter') : 'Student' ?>
            </span>
        </div>

        <!-- Hero Card -->
        <div class="card-prof" style="display: flex; flex-direction: column; gap: 1.5rem;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; width: 100%;">
                <div style="display: flex; align-items: center; gap: 1.25rem;">
                    <div class="hero-avatar-prof" style="overflow: hidden; padding: 0;">
                        <?php if (!empty($user['profile_image']) && is_file(UPLOAD_DIR . basename($user['profile_image']))): ?>
                            <img src="uploads/<?= e(basename($user['profile_image'])) ?>" alt="<?= e($user['full_name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <?= e(substr($user['full_name'] ?? 'U', 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="display: flex; align-items: center; gap: 0.625rem;">
                            <h2 style="font-size: 1.25rem; font-weight: 700; color: var(--slate-900); margin: 0;"><?= e($user['full_name'] ?? 'User Name') ?></h2>
                            <span style="display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; background: var(--emerald-100); color: var(--emerald-800);">Verified</span>
                        </div>
                        <p style="font-size: 0.875rem; font-weight: 500; color: var(--slate-600); margin-top: 2px; margin-bottom: 0;">
                            <?= $role === 'recruiter'
                                ? e(($user['designation'] ?? 'Talent Acquisition') . ' @ ' . ($company['company_name'] ?? 'Company'))
                                : e(($user['designation'] ?? 'Student') . ($studentProfile['education'] ? ' • ' . $studentProfile['education'] : '')) ?>
                        </p>
                        <div style="display: flex; align-items: center; gap: 0.75rem; margin-top: 0.5rem; font-size: 0.75rem; color: var(--slate-500);">
                            <span>📍 <?= e(($role === 'recruiter' ? ($company['location'] ?? 'Not set') : ($user['phone'] ? $user['phone'] : 'Location Not set'))) ?></span>
                            <span>🏢 <?= $role === 'recruiter' ? e($company['company_name'] ?? 'Enterprise') : 'Student Portal' ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 1.25rem; border-top: 1px solid var(--slate-100); flex-wrap: wrap; gap: 1.5rem;">
                <div style="display: flex; gap: 0.75rem; flex: 1; min-width: 280px;">
                    <div class="stat-box-prof">
                        <span class="stat-num-prof"><?= $stats['active_jobs'] ?></span>
                        <span class="stat-label-prof"><?= $role === 'recruiter' ? 'Active Jobs' : 'Applied Jobs' ?></span>
                    </div>
                    <div class="stat-box-prof brand">
                        <span class="stat-num-prof"><?= $stats['applicants'] ?></span>
                        <span class="stat-label-prof"><?= $role === 'recruiter' ? 'Applicants' : 'Saved Jobs' ?></span>
                    </div>
                    <div class="stat-box-prof emerald">
                        <span class="stat-num-prof"><?= $stats['interviews'] ?></span>
                        <span class="stat-label-prof">Interviews</span>
                    </div>
                </div>

                <div style="width: 280px;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.75rem; font-weight: 600; margin-bottom: 0.375rem;">
                        <span style="color: var(--slate-700);">Profile Strength</span>
                        <span style="color: var(--brand-600);"><?= min(100, $profileStrength) ?>% Completed</span>
                    </div>
                    <div style="width: 100%; background: var(--slate-100); height: 0.625rem; border-radius: 9999px; overflow: hidden;">
                        <div style="background: var(--brand-600); height: 100%; width: <?= min(100, $profileStrength) ?>%; border-radius: 9999px;"></div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Form Cards Grid -->
        <div class="grid-2-prof">
            <!-- Profile Picture Section -->
            <section class="card-prof" id="profile-picture">
                <h3 style="font-size: 1rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem;">Profile Picture</h3>
                <p style="font-size: 0.75rem; color: var(--slate-500); margin-bottom: 1.25rem;">Upload a photo to personalize your account avatar</p>

                <div style="display: flex; align-items: center; gap: 1.25rem; margin-bottom: 1.25rem;">
                    <div style="width: 72px; height: 72px; border-radius: 1rem; overflow: hidden; background: linear-gradient(135deg, var(--brand-600), #4338ca); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.5rem; flex-shrink: 0; box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.2); border: 2px solid var(--slate-100);">
                        <?php if (!empty($user['profile_image']) && is_file(UPLOAD_DIR . basename($user['profile_image']))): ?>
                            <img src="uploads/<?= e(basename($user['profile_image'])) ?>" alt="<?= e($user['full_name']) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <?= e(substr($user['full_name'] ?? 'U', 0, 1)) ?>
                        <?php endif; ?>
                    </div>
                    <div style="flex: 1;">
                        <h4 style="font-size: 0.875rem; font-weight: 600; color: var(--slate-800); margin: 0 0 0.25rem 0;">User Avatar</h4>
                        <p style="font-size: 0.75rem; color: var(--slate-500); margin: 0;">Supported formats: JPG, PNG, WEBP, GIF (Max 2MB)</p>
                    </div>
                </div>

                <form method="POST" action="profile.php" enctype="multipart/form-data" style="margin-bottom: 0.75rem;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="avatar">
                    <div class="form-group-prof">
                        <label class="form-label-prof" for="avatar_image">Upload New Picture</label>
                        <input class="input-control-prof" id="avatar_image" name="avatar_image" type="file" accept="image/png, image/jpeg, image/webp, image/gif" required>
                    </div>
                    <button class="btn-primary-prof" type="submit" style="width: 100%; margin-top: 0.25rem;">Upload Photo</button>
                </form>

                <?php if (!empty($user['profile_image'])): ?>
                    <form method="POST" action="profile.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="remove_avatar">
                        <button type="submit" style="width: 100%; padding: 0.5rem; border-radius: 0.75rem; font-size: 0.8125rem; font-weight: 600; color: var(--rose-600); background: var(--rose-50); border: 1px solid rgba(244, 63, 94, 0.2); cursor: pointer; transition: all 0.2s;">
                            Remove Picture
                        </button>
                    </form>
                <?php endif; ?>
            </section>

            <!-- Account Info -->
            <section class="card-prof" id="personal-info">
                <h3 style="font-size: 1rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem;">Account Information</h3>
                <p style="font-size: 0.75rem; color: var(--slate-500); margin-bottom: 1.25rem;">Personal contact credentials</p>

                <form method="POST" action="profile.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="account">
                    <div class="form-group-prof">
                        <label class="form-label-prof" for="fullName">Full Name</label>
                        <input class="input-control-prof" id="fullName" name="fullName" type="text" value="<?= e($user['full_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group-prof">
                        <label class="form-label-prof" for="phoneNumber">Phone Number</label>
                        <input class="input-control-prof" id="phoneNumber" name="phoneNumber" type="text" value="<?= e($user['phone'] ?? '') ?>">
                    </div>
                    <div class="form-group-prof">
                        <label class="form-label-prof" for="emailAddress">Email Address (Locked)</label>
                        <input class="input-control-prof" id="emailAddress" type="email" readonly value="<?= e($user['email'] ?? '') ?>">
                    </div>
                    <div class="form-group-prof">
                        <label class="form-label-prof" for="roleDesignation">Role / Designation</label>
                        <input class="input-control-prof" id="roleDesignation" name="roleDesignation" type="text" placeholder="e.g. Talent Acquisition Lead" value="<?= e($user['designation'] ?? '') ?>">
                    </div>
                    <button class="btn-primary-prof" type="submit" style="margin-top: 0.5rem; width: 100%;">Save Account Info</button>
                </form>
            </section>

            <?php if ($role === 'recruiter'): ?>
                <!-- Company Profile -->
                <section class="card-prof" id="company-profile">
                    <h3 style="font-size: 1rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem;">Company Profile</h3>
                    <p style="font-size: 0.75rem; color: var(--slate-500); margin-bottom: 1.25rem;">Brand identity seen by job candidates</p>

                    <form method="POST" action="profile.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="company">
                        <div class="form-group-prof">
                            <label class="form-label-prof" for="companyName">Company Name</label>
                            <input class="input-control-prof" id="companyName" name="companyName" type="text" value="<?= e($company['company_name'] ?? '') ?>" required>
                        </div>
                        <div class="grid-2-col-prof">
                            <div class="form-group-prof">
                                <label class="form-label-prof" for="industry">Industry</label>
                                <input class="input-control-prof" id="industry" name="industry" type="text" placeholder="e.g. Automotive & Mobility" value="<?= e($company['industry'] ?? '') ?>">
                            </div>
                            <div class="form-group-prof">
                                <label class="form-label-prof" for="company_size">Company Size</label>
                                <input class="input-control-prof" id="company_size" name="company_size" type="text" placeholder="e.g. 1,000 - 5,000+ employees" value="<?= e($company['company_size'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="grid-2-col-prof">
                            <div class="form-group-prof">
                                <label class="form-label-prof" for="website">Website</label>
                                <input class="input-control-prof" id="website" name="website" type="url" placeholder="https://example.com" value="<?= e($company['website'] ?? '') ?>">
                            </div>
                            <div class="form-group-prof">
                                <label class="form-label-prof" for="location">Headquarters</label>
                                <input class="input-control-prof" id="location" name="location" type="text" placeholder="e.g. Karur, Tamil Nadu" value="<?= e($company['location'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-group-prof">
                            <label class="form-label-prof" for="description">Company Description</label>
                            <textarea class="input-control-prof" id="description" name="description" rows="3" style="resize: vertical;"><?= e($company['description'] ?? '') ?></textarea>
                        </div>
                        <button class="btn-primary-prof" type="submit" style="width: 100%;">Save Company Info</button>
                    </form>
                </section>
            <?php else: ?>
                <!-- Student Profile -->
                <section class="card-prof" id="student-profile">
                    <h3 style="font-size: 1rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem;">Student Profile</h3>
                    <p style="font-size: 0.75rem; color: var(--slate-500); margin-bottom: 1.25rem;">Academic and skill information</p>

                    <form method="POST" action="profile.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="student">
                        <div class="form-group-prof">
                            <label class="form-label-prof" for="education">Education</label>
                            <input class="input-control-prof" id="education" name="education" type="text" placeholder="e.g. B.Tech CSE, XYZ University" value="<?= e($studentProfile['education'] ?? '') ?>">
                        </div>
                        <div class="form-group-prof">
                            <label class="form-label-prof" for="skills">Skills (Comma separated)</label>
                            <input class="input-control-prof" id="skills" name="skills" type="text" placeholder="e.g. PHP, MySQL, JavaScript, React" value="<?= e($studentProfile['skills'] ?? '') ?>">
                        </div>
                        <div class="form-group-prof">
                            <label class="form-label-prof" for="bio">Bio</label>
                            <textarea class="input-control-prof" id="bio" name="bio" rows="3" style="resize: vertical;" placeholder="Tell recruiters about yourself..."><?= e($studentProfile['bio'] ?? '') ?></textarea>
                        </div>
                        <button class="btn-primary-prof" type="submit" style="width: 100%;">Save Student Profile</button>
                    </form>
                </section>

                <!-- Resume Upload Card -->
                <section class="card-prof" id="resume-settings">
                    <h3 style="font-size: 1rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem;">Resume Document</h3>
                    <p style="font-size: 0.75rem; color: var(--slate-500); margin-bottom: 1.25rem;">Upload or update your CV</p>

                    <div style="font-size: 0.875rem; margin-bottom: 1rem; color: var(--slate-700);">
                        <?php if (!empty($studentProfile['resume_original_name'])): ?>
                            <p style="margin-bottom: 0.5rem;">Current file: <strong><?= e($studentProfile['resume_original_name']) ?></strong></p>
                            <div style="display: flex; gap: 8px; margin-bottom: 1rem;">
                                <a href="download-resume.php?mode=preview" target="_blank" class="btn-primary-prof" style="font-size: 0.75rem; padding: 0.4rem 0.875rem; text-decoration: none;">Preview Resume</a>
                                <a href="download-resume.php" class="btn-primary-prof" style="background: var(--slate-700); font-size: 0.75rem; padding: 0.4rem 0.875rem; text-decoration: none;">Download</a>
                            </div>
                        <?php else: ?>
                            <span style="color: var(--rose-600);">No resume uploaded yet. You need one to apply for jobs.</span>
                        <?php endif; ?>
                    </div>

                    <form method="POST" action="profile.php" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="form" value="resume">
                        <div class="form-group-prof">
                            <label class="form-label-prof" for="resume">Upload New Resume (PDF, DOC, DOCX - Max 5MB)</label>
                            <input class="input-control-prof" id="resume" name="resume" type="file" accept=".pdf,.doc,.docx" required>
                        </div>
                        <button class="btn-primary-prof" type="submit" style="width: 100%; margin-top: 0.5rem;">Upload Resume</button>
                    </form>
                </section>
            <?php endif; ?>
        </div>

        <!-- Security Section -->
        <section class="card-prof" id="security-settings">
            <h3 style="font-size: 1rem; font-weight: 700; color: var(--slate-900); margin-bottom: 0.25rem;">Change Password</h3>
            <p style="font-size: 0.75rem; color: var(--slate-500); margin-bottom: 1.25rem;">Ensure account safety with strong authentication credentials</p>

            <form method="POST" action="profile.php">
                <?= csrf_field() ?>
                <input type="hidden" name="form" value="password">
                <div class="grid-2-col-prof">
                    <div class="form-group-prof">
                        <label class="form-label-prof" for="currentPassword">Current Password</label>
                        <input class="input-control-prof" id="currentPassword" name="currentPassword" type="password" required>
                    </div>
                    <div class="form-group-prof">
                        <label class="form-label-prof" for="newPassword">New Password</label>
                        <input class="input-control-prof" id="newPassword" name="newPassword" placeholder="Enter new strong password" type="password" required>
                    </div>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                    <span style="font-size: 0.75rem; color: var(--slate-600);">Must be at least 8 characters long and include letters & numbers</span>
                    <button class="btn-primary-prof" type="submit">Update Password</button>
                </div>
            </form>
        </section>
    </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>