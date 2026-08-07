<?php
/**
 * register-student.php
 * Student sign-up form (GET) and account creation (POST).
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_guest();

if (is_post()) {
    verify_csrf();

    $fullName = post('full_name');
    $email = strtolower(post('email'));
    $phone = post('phone');

    $error = create_account(
        get_db(),
        'student',
        $fullName,
        $email,
        $phone,
        (string) ($_POST['password'] ?? '')
    );

    if ($error !== '') {
        fail($error, ['full_name' => $fullName, 'email' => $email, 'phone' => $phone]);
        redirect('register-student.php');
    }

    flash('Welcome to JobPortal! Upload your resume to start applying.');
    redirect('student-dashboard.php');
}

$error = take_error();
$old = take_old();

$pageTitle = 'Student Registration';
require __DIR__ . '/header.php';
?>

<div class="auth-shell">
  <div class="card auth-card">
    <h2 class="text-center">Create your student account</h2>
    <p class="text-center">Find jobs and internships that match your skills</p>

    <?php if ($error): ?>
      <div class="alert alert-error show"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="register-student.php">
      <?= csrf_field() ?>
      <div class="form-group">
        <label for="full_name">Full name</label>
        <input type="text" id="full_name" name="full_name" required maxlength="150" autocomplete="name"
               value="<?= e(old($old, 'full_name')) ?>">
      </div>
      <div class="form-group">
        <label for="email">Email address</label>
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
      Hiring instead? <a href="register-recruiter.php">Register as Recruiter</a>
    </p>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
