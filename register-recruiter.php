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

// Live counters for the pitch column, same source as the public pages.
$pdo = get_db();
$studentCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "student"')->fetchColumn();
$companyCount = (int) $pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn();
$applicationCount = (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn();

$pageTitle = 'Recruiter Registration | CareerStudio';
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
            <span class="material-symbols-outlined" style="font-size: 14px;">apartment</span> For recruiters
          </span>
          <h1 class="display-lg">Hire from a pool that is already looking</h1>
          <p class="body-lg text-variant" style="margin-top: 12px;">Post a role, collect applications with resumes
            attached, and move candidates through one pipeline.</p>
        </div>

        <div class="auth-benefits">
          <div class="benefit">
            <span class="benefit-icon material-symbols-outlined">post_add</span>
            <div>
              <div class="benefit-title">Post a role in minutes</div>
              <div class="body-sm text-variant">It appears instantly on the Jobs, Companies and Remote boards.</div>
            </div>
          </div>
          <div class="benefit">
            <span class="benefit-icon material-symbols-outlined">group</span>
            <div>
              <div class="benefit-title">Review every applicant in one place</div>
              <div class="body-sm text-variant">Resumes, profiles and statuses on a single screen.</div>
            </div>
          </div>
          <div class="benefit">
            <span class="benefit-icon material-symbols-outlined">event_available</span>
            <div>
              <div class="benefit-title">Schedule interviews directly</div>
              <div class="body-sm text-variant">Set a slot and it lands on the candidate's dashboard.</div>
            </div>
          </div>
        </div>

        <div class="auth-stats">
          <div>
            <div class="stat-value" data-live="students"><?= $studentCount ?></div>
            <div class="label-sm text-variant">Students registered</div>
          </div>
          <div>
            <div class="stat-value" data-live="companies"><?= $companyCount ?></div>
            <div class="label-sm text-variant">Companies onboard</div>
          </div>
          <div>
            <div class="stat-value" data-live="applications"><?= $applicationCount ?></div>
            <div class="label-sm text-variant">Applications sent</div>
          </div>
        </div>
      </div>

      <!-- Form card -->
      <div class="auth-panel">
        <div class="role-toggle">
          <a href="register-student.php">I'm a Student</a>
          <a class="active" href="register-recruiter.php">I'm a Recruiter</a>
        </div>

        <h2 class="headline-md">Create your recruiter account</h2>
        <p class="body-sm text-variant" style="margin: 4px 0 24px;">Your company profile is created with it — you can
          flesh it out later.</p>

        <?php if ($error): ?>
          <div class="form-alert">
            <span class="material-symbols-outlined" style="font-size: 18px;">error</span>
            <span><?= e($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="post" action="register-recruiter.php">
          <?= csrf_field() ?>
          <div class="field">
            <label for="full_name">Your full name</label>
            <input type="text" id="full_name" name="full_name" required maxlength="150" autocomplete="name"
                   placeholder="Your full name" value="<?= e(old($old, 'full_name')) ?>">
          </div>
          <div class="field">
            <label for="company_name">Company name</label>
            <input type="text" id="company_name" name="company_name" required maxlength="150"
                   placeholder="e.g. Zoho" value="<?= e(old($old, 'company_name')) ?>">
          </div>
          <div class="field">
            <label for="email">Work email</label>
            <input type="email" id="email" name="email" required autocomplete="email"
                   placeholder="you@company.com" value="<?= e(old($old, 'email')) ?>">
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
