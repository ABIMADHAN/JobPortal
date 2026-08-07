<?php
/**
 * register-recruiter.php
 * Recruiter sign-up form (GET) and account + company creation (POST).
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_guest();

if (is_post()) {
    verify_csrf();

    $fullName = post('full_name');
    $companyName = post('company_name');
    $email = strtolower(post('email'));
    $phone = post('phone');

    $error = create_account(
        get_db(),
        'recruiter',
        $fullName,
        $email,
        $phone,
        (string) ($_POST['password'] ?? ''),
        $companyName
    );

    if ($error !== '') {
        fail($error, [
            'full_name' => $fullName,
            'company_name' => $companyName,
            'email' => $email,
            'phone' => $phone,
        ]);
        redirect('register-recruiter.php');
    }

    flash('Account created. Post your first job to start hiring.');
    redirect('recruiter-dashboard.php');
}

$error = take_error();
$old = take_old();

$pageTitle = 'Recruiter Registration';
require __DIR__ . '/header.php';
?>

<div class="auth-shell">
  <div class="card auth-card">
    <h2 class="text-center">Create your recruiter account</h2>
    <p class="text-center">Post jobs and manage applicants</p>

    <?php if ($error): ?>
      <div class="alert alert-error show"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="register-recruiter.php">
      <?= csrf_field() ?>
      <div class="form-group">
        <label for="full_name">Your full name</label>
        <input type="text" id="full_name" name="full_name" required maxlength="150" autocomplete="name"
               value="<?= e(old($old, 'full_name')) ?>">
      </div>
      <div class="form-group">
        <label for="company_name">Company name</label>
        <input type="text" id="company_name" name="company_name" required maxlength="150"
               value="<?= e(old($old, 'company_name')) ?>">
      </div>
      <div class="form-group">
        <label for="email">Work email</label>
        <input type="email" id="email" name="email" required autocomplete="email"
               value="<?= e(old($old, 'email')) ?>">
      </div>
      <div class="form-group">
        <label for="phone">Phone number</label>
        <input type="tel" id="phone" name="phone" autocomplete="tel"
               value="<?= e(old($old, 'phone')) ?>">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="new-password">
        <div class="form-hint">At least 8 characters, including a letter and a number.</div>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Create Account</button>
    </form>

    <p class="auth-switch">
      Already have an account? <a href="login.php">Login</a><br>
      Looking for a job? <a href="register-student.php">Register as Student</a>
    </p>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
