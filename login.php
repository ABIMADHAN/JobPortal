<?php
/**
 * login.php
 * Shows the login form (GET) and authenticates the user (POST).
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

require_guest();

if (is_post()) {
    verify_csrf();

    $email = strtolower(post('email'));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        fail('Please enter both your email and password.', ['email' => $email]);
        redirect('login.php');
    }

    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id, role, full_name, password_hash, is_active FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        fail('Invalid email or password.', ['email' => $email]);
        redirect('login.php');
    }

    if ((int) $user['is_active'] !== 1) {
        fail('This account has been deactivated.', ['email' => $email]);
        redirect('login.php');
    }

    log_in_user((int) $user['id'], $user['role'], $user['full_name']);
    flash('Welcome back, ' . $user['full_name'] . '!');
    redirect(dashboard_url($user['role']));
}

$error = take_error();
$old = take_old();

$pageTitle = 'Login';
$activeNav = 'login';
require __DIR__ . '/header.php';
?>

<div class="auth-shell">
  <div class="card auth-card">
    <h2 class="text-center">Welcome back</h2>
    <p class="text-center">Login to continue to JobPortal</p>

    <?php if ($error): ?>
      <div class="alert alert-error show"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php">
      <?= csrf_field() ?>
      <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required autocomplete="email"
               value="<?= e(old($old, 'email')) ?>">
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Login</button>
    </form>

    <p class="auth-switch">
      New to JobPortal?
      <a href="register-student.php">Register as Student</a> or
      <a href="register-recruiter.php">Register as Recruiter</a>
    </p>
  </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
