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

// Live counters for the pitch column, same source as the public pages.
$pdo = get_db();
$openJobCount = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open"')->fetchColumn();
$hiringCount = (int) $pdo->query('SELECT COUNT(DISTINCT company_id) FROM jobs WHERE status = "open"')->fetchColumn();
$studentCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "student"')->fetchColumn();

$pageTitle = 'Login | CareerStudio';
$activeNav = '';
require __DIR__ . '/site-nav.php';
?>

<main>
  <section class="container auth-section">
    <div class="auth-grid">

      <!-- Pitch column -->
      <div class="auth-aside">
        <div>
          <span class="eyebrow">
            <span class="material-symbols-outlined" style="font-size: 14px;">login</span> Sign in
          </span>
          <h1 class="display-lg">Pick up where you left off</h1>
          <p class="body-lg text-variant" style="margin-top: 12px;">Your applications, saved jobs and interview
            schedule are all waiting in your pipeline.</p>
        </div>

        <div class="auth-benefits">
          <div class="benefit">
            <span class="benefit-icon material-symbols-outlined">timeline</span>
            <div>
              <div class="benefit-title">Track every application</div>
              <div class="body-sm text-variant">See what is under review, shortlisted or scheduled — in one board.</div>
            </div>
          </div>
          <div class="benefit">
            <span class="benefit-icon material-symbols-outlined">bookmark</span>
            <div>
              <div class="benefit-title">Your wishlist, saved</div>
              <div class="body-sm text-variant">Bookmarked roles stay with your account across devices.</div>
            </div>
          </div>
          <div class="benefit">
            <span class="benefit-icon material-symbols-outlined">event_available</span>
            <div>
              <div class="benefit-title">Never miss an interview</div>
              <div class="body-sm text-variant">Recruiters schedule slots straight onto your dashboard.</div>
            </div>
          </div>
        </div>

        <div class="auth-stats">
          <div>
            <div class="stat-value" data-live="open_jobs"><?= $openJobCount ?></div>
            <div class="label-sm text-variant">Open roles</div>
          </div>
          <div>
            <div class="stat-value" data-live="hiring"><?= $hiringCount ?></div>
            <div class="label-sm text-variant">Companies hiring</div>
          </div>
          <div>
            <div class="stat-value" data-live="students"><?= $studentCount ?></div>
            <div class="label-sm text-variant">Students registered</div>
          </div>
        </div>
      </div>

      <!-- Form card -->
      <div class="auth-panel">
        <h2 class="headline-md">Welcome back</h2>
        <p class="body-sm text-variant" style="margin: 4px 0 24px;">Log in to continue to CareerStudio.</p>

        <?php if ($error): ?>
          <div class="form-alert">
            <span class="material-symbols-outlined" style="font-size: 18px;">error</span>
            <span><?= e($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="post" action="login.php">
          <?= csrf_field() ?>
          <div class="field">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" required autocomplete="email"
                   placeholder="you@example.com" value="<?= e(old($old, 'email')) ?>">
          </div>
          <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="current-password"
                   placeholder="Your password">
          </div>
          <button type="submit" class="btn btn-primary btn-block">Log in</button>
        </form>

        <p class="auth-switch">
          New to CareerStudio?<br>
          <a href="register-student.php">Register as a Student</a> &middot;
          <a href="register-recruiter.php">Register as a Recruiter</a>
        </p>
      </div>

    </div>
  </section>
</main>

<script src="live-stats.js" defer></script>
<?php require __DIR__ . '/site-footer.php'; ?>
