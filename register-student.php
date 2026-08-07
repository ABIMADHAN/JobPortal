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

// Live counters for the pitch column, same source as the public pages.
$pdo = get_db();
$openJobCount = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open"')->fetchColumn();
$internshipCount = (int) $pdo->query('SELECT COUNT(*) FROM jobs WHERE status = "open" AND job_type = "internship"')->fetchColumn();
$hiringCount = (int) $pdo->query('SELECT COUNT(DISTINCT company_id) FROM jobs WHERE status = "open"')->fetchColumn();

$pageTitle = 'Student Registration | CareerStudio';
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
            <span class="material-symbols-outlined" style="font-size: 14px;">school</span> For students
          </span>
          <h1 class="display-lg">Start applying in minutes</h1>
          <p class="body-lg text-variant" style="margin-top: 12px;">One profile, one resume, and every job and
            internship on CareerStudio opens up to you.</p>
        </div>

        <div class="auth-benefits">
          <div class="benefit">
            <span class="benefit-icon material-symbols-outlined">bolt</span>
            <div>
              <div class="benefit-title">Apply with one click</div>
              <div class="body-sm text-variant">Upload your resume once and reuse it on every application.</div>
            </div>
          </div>
          <div class="benefit">
            <span class="benefit-icon material-symbols-outlined">timeline</span>
            <div>
              <div class="benefit-title">Follow your pipeline</div>
              <div class="body-sm text-variant">Applied, under review, shortlisted, hired — always know where you stand.</div>
            </div>
          </div>
          <div class="benefit">
            <span class="benefit-icon material-symbols-outlined">bookmark</span>
            <div>
              <div class="benefit-title">Save roles for later</div>
              <div class="body-sm text-variant">Build a wishlist while you browse and come back when you are ready.</div>
            </div>
          </div>
        </div>

        <div class="auth-stats">
          <div>
            <div class="stat-value" data-live="open_jobs"><?= $openJobCount ?></div>
            <div class="label-sm text-variant">Open roles</div>
          </div>
          <div>
            <div class="stat-value" data-live="internships"><?= $internshipCount ?></div>
            <div class="label-sm text-variant">Internships</div>
          </div>
          <div>
            <div class="stat-value" data-live="hiring"><?= $hiringCount ?></div>
            <div class="label-sm text-variant">Companies hiring</div>
          </div>
        </div>
      </div>

      <!-- Form card -->
      <div class="auth-panel">
        <div class="role-toggle">
          <a class="active" href="register-student.php">I'm a Student</a>
          <a href="register-recruiter.php">I'm a Recruiter</a>
        </div>

        <h2 class="headline-md">Create your student account</h2>
        <p class="body-sm text-variant" style="margin: 4px 0 24px;">Free forever. No credit card, no catch.</p>

        <?php if ($error): ?>
          <div class="form-alert">
            <span class="material-symbols-outlined" style="font-size: 18px;">error</span>
            <span><?= e($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="post" action="register-student.php">
          <?= csrf_field() ?>
          <div class="field">
            <label for="full_name">Full name</label>
            <input type="text" id="full_name" name="full_name" required maxlength="150" autocomplete="name"
                   placeholder="Your full name" value="<?= e(old($old, 'full_name')) ?>">
          </div>
          <div class="field">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" required autocomplete="email"
                   placeholder="you@example.com" value="<?= e(old($old, 'email')) ?>">
          </div>
          <div class="field">
            <label for="phone">Phone number <span class="text-variant">(optional)</span></label>
            <input type="tel" id="phone" name="phone" autocomplete="tel"
                   placeholder="+91 98765 43210" value="<?= e(old($old, 'phone')) ?>">
          </div>
          <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="new-password"
                   placeholder="Create a password">
            <div class="field-hint">At least 8 characters, including a letter and a number.</div>
          </div>
          <button type="submit" class="btn btn-primary btn-block">Create Account</button>
        </form>

        <p class="auth-switch">
          Already have an account? <a href="login.php">Log in</a>
        </p>
      </div>

    </div>
  </section>
</main>

<script src="live-stats.js" defer></script>
<?php require __DIR__ . '/site-footer.php'; ?>
