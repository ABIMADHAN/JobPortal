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

/** "under_review" -> "under review" */
function status_label(?string $status): string
{
    return str_replace('_', ' ', (string) $status);
}

/** First letter of a name, for avatar chips. */
function initials(string $name): string
{
    $name = trim($name);
    return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
}

// ---- Redirects ----

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
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
