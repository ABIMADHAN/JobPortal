<?php
/**
 * auth.php
 * Authentication and authorization helpers for page-level access control.
 * Every page in this project starts with: require_once __DIR__ . '/auth.php';
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function current_user_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function current_user_name(): string
{
    return (string) ($_SESSION['full_name'] ?? '');
}

function is_logged_in(): bool
{
    return current_user_id() !== null;
}

function dashboard_url(?string $role = null): string
{
    $role = $role ?? current_user_role();
    return $role === 'recruiter' ? 'recruiter-dashboard.php' : 'student-dashboard.php';
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('Please log in to continue.', 'error');
        redirect('login.php');
    }
}

function require_role(string $role): void
{
    require_login();
    if (current_user_role() !== $role) {
        flash('You are not authorized to view that page.', 'error');
        redirect(dashboard_url());
    }
}

/** Send already-logged-in visitors away from login/register pages. */
function require_guest(): void
{
    if (is_logged_in()) {
        redirect(dashboard_url());
    }
}

function log_in_user(int $userId, string $role, string $fullName): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = $role;
    $_SESSION['full_name'] = $fullName;
}

function log_out_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Count for the topbar notification dot.
 *   recruiter -> applications still waiting to be reviewed
 *   student   -> good news (shortlisted/hired) plus any scheduled interview
 */
function notification_count(PDO $pdo): int
{
    $userId = current_user_id();
    if ($userId === null) {
        return 0;
    }

    if (current_user_role() === 'recruiter') {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM applications a
             INNER JOIN jobs j ON a.job_id = j.id
             INNER JOIN companies c ON j.company_id = c.id
             WHERE c.user_id = :uid AND a.status IN ("applied", "under_review")'
        );
    } else {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM applications
             WHERE student_id = :uid
               AND (status IN ("shortlisted", "hired")
                    OR (interview_at IS NOT NULL AND interview_at >= NOW()))'
        );
    }

    $stmt->execute([':uid' => $userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Scheduled interviews that haven't happened yet, newest first.
 * Students see the hiring company; recruiters see the candidate.
 */
function upcoming_interviews(PDO $pdo, int $limit = 4): array
{
    $userId = current_user_id();
    if ($userId === null) {
        return [];
    }

    $limit = max(1, min(20, $limit)); // interpolated below, so clamp it hard

    if (current_user_role() === 'recruiter') {
        $sql = "SELECT a.id, a.interview_at, j.title AS job_title,
                       u.full_name AS person, 'Candidate' AS person_role
                FROM applications a
                INNER JOIN jobs j ON a.job_id = j.id
                INNER JOIN companies c ON j.company_id = c.id
                INNER JOIN users u ON a.student_id = u.id
                WHERE c.user_id = :uid
                  AND a.interview_at IS NOT NULL AND a.interview_at >= NOW()
                  AND a.status NOT IN ('withdrawn', 'rejected')
                ORDER BY a.interview_at ASC
                LIMIT $limit";
    } else {
        $sql = "SELECT a.id, a.interview_at, j.title AS job_title,
                       c.company_name AS person, 'Hiring company' AS person_role
                FROM applications a
                INNER JOIN jobs j ON a.job_id = j.id
                INNER JOIN companies c ON j.company_id = c.id
                WHERE a.student_id = :uid
                  AND a.interview_at IS NOT NULL AND a.interview_at >= NOW()
                  AND a.status NOT IN ('withdrawn', 'rejected')
                ORDER BY a.interview_at ASC
                LIMIT $limit";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Creates a user (+ their student profile or company row) and logs them in.
 * Returns an error message on failure, or '' on success.
 */
function create_account(PDO $pdo, string $role, string $fullName, string $email, string $phone, string $password, string $companyName = ''): string
{
    if (!in_array($role, ['student', 'recruiter'], true)) {
        return 'Invalid account type.';
    }

    $missing = first_missing([
        'full name' => $fullName,
        'email address' => $email,
        'password' => $password,
    ]);
    if ($missing !== '') {
        return 'Please enter your ' . $missing . '.';
    }
    if ($role === 'recruiter' && $companyName === '') {
        return 'Please enter your company name.';
    }
    if (strlen($fullName) > 150) {
        return 'Full name is too long.';
    }
    if (!is_valid_email($email)) {
        return 'Please enter a valid email address.';
    }
    if (!is_valid_password($password)) {
        return 'Password must be at least 8 characters and include a letter and a number.';
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        return 'An account with this email already exists.';
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO users (role, full_name, email, password_hash, phone)
             VALUES (:role, :full_name, :email, :password_hash, :phone)'
        );
        $stmt->execute([
            ':role' => $role,
            ':full_name' => $fullName,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':phone' => $phone !== '' ? $phone : null,
        ]);

        $userId = (int) $pdo->lastInsertId();

        if ($role === 'student') {
            $stmt = $pdo->prepare('INSERT INTO student_profiles (user_id) VALUES (:user_id)');
            $stmt->execute([':user_id' => $userId]);
        } else {
            // Placeholder company profile the recruiter can flesh out later.
            $stmt = $pdo->prepare('INSERT INTO companies (user_id, company_name) VALUES (:user_id, :company_name)');
            $stmt->execute([':user_id' => $userId, ':company_name' => $companyName]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return 'Registration failed. Please try again.';
    }

    log_in_user($userId, $role, $fullName);
    return '';
}

/**
 * Returns the company row owned by the logged-in recruiter.
 * Registration always creates one, so a missing row means a broken account.
 */
function get_owned_company(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $company = $stmt->fetch();

    if (!$company) {
        // Self-heal: create a placeholder company so the recruiter can carry on.
        $stmt = $pdo->prepare('INSERT INTO companies (user_id, company_name) VALUES (:uid, :name)');
        $stmt->execute([':uid' => $userId, ':name' => current_user_name() . "'s Company"]);

        $stmt = $pdo->prepare('SELECT * FROM companies WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $company = $stmt->fetch();
    }

    return $company;
}
