<?php
/**
 * profile.php
 * Role-aware profile page. Each card is its own form; the hidden "form" field
 * tells the POST handler which one was submitted.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_login();

$pdo = get_db();
$userId = (int) current_user_id();
$role = (string) current_user_role();

// ---------------------------------------------------------------
// POST handlers
// ---------------------------------------------------------------
if (is_post()) {
    verify_csrf();
    $which = post('form');

    // ---- Account info (both roles) ----
    if ($which === 'account') {
        $fullName = post('full_name');
        $phone = post('phone');

        if ($fullName === '' || strlen($fullName) > 150) {
            flash('Please enter a valid full name (max 150 characters).', 'error');
            redirect('profile.php');
        }

        $stmt = $pdo->prepare('UPDATE users SET full_name = :name, phone = :phone WHERE id = :id');
        $stmt->execute([':name' => $fullName, ':phone' => $phone !== '' ? $phone : null, ':id' => $userId]);

        $_SESSION['full_name'] = $fullName;
        flash('Account info saved.');
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

        flash('Profile saved.');
        redirect('profile.php');
    }

    // ---- Company profile ----
    if ($which === 'company' && $role === 'recruiter') {
        $companyName = post('company_name');
        $website = post('website');

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
                                  website = :website, location = :location
             WHERE user_id = :uid'
        );
        $stmt->execute([
            ':name' => $companyName,
            ':description' => post('description'),
            ':website' => $website,
            ':location' => post('location'),
            ':uid' => $userId,
        ]);

        flash('Company info saved.');
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

        // Delete the previous resume so uploads/ doesn't collect orphans.
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
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');

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
// GET: load everything this page renders
// ---------------------------------------------------------------
$stmt = $pdo->prepare('SELECT full_name, email, phone FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

$studentProfile = [];
$company = [];

if ($role === 'student') {
    $stmt = $pdo->prepare(
        'SELECT education, skills, bio, resume_original_name
         FROM student_profiles WHERE user_id = :uid LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $studentProfile = $stmt->fetch() ?: [];
} else {
    $company = get_owned_company($pdo, $userId);
}

$pageTitle = 'My Profile';
$topbarTitle = 'My Profile';
$activeNav = 'profile';
$layout = 'app';
require __DIR__ . '/header.php';
?>

<main class="canvas" style="max-width:760px;">
  <h1>My Profile</h1>

  <!-- ---------- Account info (both roles) ---------- -->
  <div class="card">
    <h2>Account Information</h2>
    <form method="post" action="profile.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="account">
      <div class="form-row">
        <div class="form-group">
          <label for="full_name">Full name</label>
          <input type="text" id="full_name" name="full_name" required maxlength="150"
                 value="<?= e($user['full_name']) ?>">
        </div>
        <div class="form-group">
          <label for="phone">Phone number</label>
          <input type="tel" id="phone" name="phone" value="<?= e($user['phone']) ?>">
        </div>
      </div>
      <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" value="<?= e($user['email']) ?>" disabled>
        <div class="form-hint">Email cannot be changed.</div>
      </div>
      <button type="submit" class="btn btn-primary">Save Account Info</button>
    </form>
  </div>

  <?php if ($role === 'student'): ?>
    <!-- ---------- Student profile ---------- -->
    <div class="card">
      <h2>Student Profile</h2>
      <form method="post" action="profile.php">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="student">
        <div class="form-group">
          <label for="education">Education</label>
          <input type="text" id="education" name="education" placeholder="e.g. B.Tech CSE, XYZ University, 2026"
                 value="<?= e($studentProfile['education'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="skills">Skills</label>
          <input type="text" id="skills" name="skills" placeholder="e.g. JavaScript, PHP, MySQL"
                 value="<?= e($studentProfile['skills'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="bio">Bio</label>
          <textarea id="bio" name="bio" placeholder="Tell recruiters about yourself"><?= e($studentProfile['bio'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Save Profile</button>
      </form>

      <hr class="divider">

      <h3>Resume</h3>
      <p>
        <?php if (!empty($studentProfile['resume_original_name'])): ?>
          Current resume: <strong><?= e($studentProfile['resume_original_name']) ?></strong>
        <?php else: ?>
          No resume uploaded yet. You need one before you can apply for jobs.
        <?php endif; ?>
      </p>
      <form method="post" action="profile.php" enctype="multipart/form-data" class="flex gap-8" style="align-items:center;">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="resume">
        <input type="file" name="resume" accept=".pdf,.doc,.docx" required>
        <button type="submit" class="btn btn-secondary">Upload</button>
      </form>
      <div class="form-hint">PDF, DOC or DOCX. Max size 5MB.</div>
    </div>
  <?php else: ?>
    <!-- ---------- Company profile ---------- -->
    <div class="card">
      <h2>Company Profile</h2>
      <form method="post" action="profile.php">
        <?= csrf_field() ?>
        <input type="hidden" name="form" value="company">
        <div class="form-group">
          <label for="company_name">Company name</label>
          <input type="text" id="company_name" name="company_name" required maxlength="150"
                 value="<?= e($company['company_name'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="description">Description</label>
          <textarea id="description" name="description" placeholder="What does your company do?"><?= e($company['description'] ?? '') ?></textarea>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label for="website">Website</label>
            <input type="url" id="website" name="website" placeholder="https://example.com"
                   value="<?= e($company['website'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="location">Location</label>
            <input type="text" id="location" name="location" value="<?= e($company['location'] ?? '') ?>">
          </div>
        </div>
        <button type="submit" class="btn btn-primary">Save Company Info</button>
      </form>
    </div>
  <?php endif; ?>

  <!-- ---------- Change password (both roles) ---------- -->
  <div class="card">
    <h2>Change Password</h2>
    <form method="post" action="profile.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form" value="password">
      <div class="form-group">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
      </div>
      <div class="form-group">
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
        <div class="form-hint">At least 8 characters, including a letter and a number.</div>
      </div>
      <button type="submit" class="btn btn-primary">Update Password</button>
    </form>
  </div>
</main>

<?php require __DIR__ . '/footer.php'; ?>
