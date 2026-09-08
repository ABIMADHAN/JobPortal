<?php
/**
 * functions.php
 * Shared helpers: session bootstrap, escaping, validation, flash messages,
 * CSRF tokens, old-input repopulation, redirects and upload validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Output escaping ----

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Only allow http(s) links so a stored "javascript:" URL can never execute. */
function safe_url(?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    return preg_match('#^https?://#i', $url) ? $url : '';
}

function format_date(?string $dateStr): string
{
    if (!$dateStr) {
        return '-';
    }
    $ts = strtotime($dateStr);
    return $ts ? date('M j, Y', $ts) : $dateStr;
}

function format_datetime(?string $value): string
{
    if (!$value) {
        return '-';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y · g:i A', $ts) : $value;
}

/** Value for a <input type="datetime-local"> field. */
function datetime_local(?string $value): string
{
    if (!$value) {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

/** "under_review" -> "Under Review" */
function status_label(?string $status): string
{
    return ucwords(str_replace('_', ' ', (string) $status));
}

/** First letter of a name, for avatar chips. */
function initials(string $name): string
{
    $name = trim($name);
    return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
}

/** First name only — "Hi Madhan" reads better than "Hi Madhan G". */
if (!function_exists('first_name')) {
    function first_name(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName));
        return $parts[0] ?? $fullName;
    }
}

/** Stable accent colour for a logo tile, derived from the name so it never flickers. */
function avatar_color(string $seed): string
{
    $palette = ['#4648d4', '#0F172A', '#0EA5E9', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];
    return $palette[crc32($seed) % count($palette)];
}

// ---- Redirects ----

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/**
 * Absolute URL to a page in this app — emails cannot use the relative links the
 * rest of the site does. Set APP_URL in .env for links that work outside
 * localhost; otherwise it is derived from the current request.
 */
function app_url(string $path = ''): string
{
    $base = env('APP_URL');

    if (!$base) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        $base = $scheme . '://' . $host . $dir;
    }

    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

// ---- Flash messages (survive one redirect) ----

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

// ---- Form errors + old input (repopulate a form after a failed POST) ----

function fail(string $message, array $input = []): void
{
    $_SESSION['error'] = $message;
    $_SESSION['old'] = $input;
}

function take_error(): string
{
    $error = $_SESSION['error'] ?? '';
    unset($_SESSION['error']);
    return (string) $error;
}

function take_old(): array
{
    $old = $_SESSION['old'] ?? [];
    unset($_SESSION['old']);
    return is_array($old) ? $old : [];
}

/** Value for a form field: submitted-but-rejected value first, then the DB value. */
function old(array $oldInput, string $key, ?string $fallback = ''): string
{
    return (string) ($oldInput[$key] ?? $fallback ?? '');
}

// ---- Request helpers ----

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function query(string $key, string $default = ''): string
{
    return trim((string) ($_GET[$key] ?? $default));
}

// ---- Validation ----

function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function is_valid_password(string $password): bool
{
    // At least 8 chars, one letter, one number
    return strlen($password) >= 8
        && preg_match('/[A-Za-z]/', $password) === 1
        && preg_match('/[0-9]/', $password) === 1;
}

/** Returns the name of the first empty required field, or '' when all are present. */
function first_missing(array $fields): string
{
    foreach ($fields as $label => $value) {
        if (trim((string) $value) === '') {
            return $label;
        }
    }
    return '';
}

// ---- CSRF protection ----

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        exit('Invalid or expired form token. Please go back and try again.');
    }
}

// ---- File upload helpers ----

function secure_random_filename(string $extension): string
{
    return bin2hex(random_bytes(16)) . '.' . strtolower($extension);
}

/** Returns an error message, or '' when the upload is valid. */
function validate_resume_upload(array $file): string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return 'File upload failed. Please try again.';
    }
    if ($file['size'] > RESUME_MAX_SIZE) {
        return 'File exceeds the maximum size of 5MB.';
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, RESUME_ALLOWED_EXT, true)) {
        return 'Invalid file extension. Allowed: PDF, DOC, DOCX.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, RESUME_ALLOWED_MIME, true)) {
        return 'Invalid file type detected.';
    }

    return '';
}

/** Returns an error message, or '' when the profile image upload is valid. */
function validate_avatar_upload(array $file): string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return 'Image upload failed. Please try again.';
    }
    if ($file['size'] > AVATAR_MAX_SIZE) {
        return 'Image exceeds the maximum size of 2MB.';
    }

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, AVATAR_ALLOWED_EXT, true)) {
        return 'Invalid image extension. Allowed: JPG, JPEG, PNG, WEBP, GIF.';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, AVATAR_ALLOWED_MIME, true)) {
        return 'Invalid image file type detected.';
    }

    return '';
}

/** Ensure the users table has the profile_image column if it doesn't already exist. */
function ensure_profile_image_column(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_image'");
        if ($stmt->fetch() === false) {
            $pdo->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) DEFAULT NULL AFTER phone");
        }
    } catch (Exception $e) {
        // Silently catch if column exists or schema differs
    }
}

// ---- Pagination ----

/** @return array{0:int,1:int,2:int} [page, limit, offset] */
function get_pagination(array $params, int $defaultLimit = 8, int $maxLimit = 50): array
{
    $page = isset($params['page']) ? max(1, (int) $params['page']) : 1;
    $limit = isset($params['limit']) ? (int) $params['limit'] : $defaultLimit;
    $limit = max(1, min($maxLimit, $limit));
    $offset = ($page - 1) * $limit;
    return [$page, $limit, $offset];
}

/** The current URL with ?page= swapped out, so filters survive paging. */
function page_url(int $targetPage): string
{
    $qs = $_GET;
    $qs['page'] = $targetPage;
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) . '?' . http_build_query($qs);
}

// ---- Auto Job Expiry Handler ----

/**
 * Automatically updates jobs whose application deadline has passed (deadline < CURDATE())
 * from 'open' status to 'closed'. Returns the number of newly closed jobs.
 */
function auto_close_expired_jobs(PDO $pdo): int
{
    try {
        $stmt = $pdo->prepare(
            "UPDATE jobs 
             SET status = 'closed', updated_at = CURRENT_TIMESTAMP 
             WHERE status = 'open' AND deadline IS NOT NULL AND deadline < CURDATE()"
        );
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}

/** Dynamically ensure 'meeting_link' column exists in applications table. */
function ensure_meeting_link_column(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM applications LIKE 'meeting_link'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE applications ADD COLUMN meeting_link VARCHAR(500) DEFAULT NULL AFTER interview_at");
        }
    } catch (Exception $e) {
        // Silently ignore schema errors
    }
}

// ---- Notifications Center Helpers ----

/** Ensures the user_notifications table exists in the database. */
function ensure_notifications_table(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_notifications (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id      INT UNSIGNED NOT NULL,
                title        VARCHAR(255) NOT NULL,
                message      TEXT NOT NULL,
                type         VARCHAR(50) NOT NULL DEFAULT 'system',
                link         VARCHAR(255) DEFAULT NULL,
                is_read      TINYINT(1) NOT NULL DEFAULT 0,
                created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_read (user_id, is_read, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) {
        // Silently continue if table creation fails
    }
}

/** Create a new in-app notification for a user. */
function create_notification(PDO $pdo, int $userId, string $title, string $message, string $type = 'system', ?string $link = null): bool
{
    ensure_notifications_table($pdo);
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO user_notifications (user_id, title, message, type, link)
             VALUES (:uid, :title, :message, :type, :link)'
        );
        return $stmt->execute([
            ':uid' => $userId,
            ':title' => $title,
            ':message' => $message,
            ':type' => $type,
            ':link' => $link,
        ]);
    } catch (Exception $e) {
        return false;
    }
}

/** Get unread notification count for user. */
function get_unread_notification_count(PDO $pdo, ?int $userId = null): int
{
    $userId = $userId ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    if (!$userId) return 0;
    ensure_notifications_table($pdo);
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = :uid AND is_read = 0');
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

