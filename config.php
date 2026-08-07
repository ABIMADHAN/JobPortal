<?php
/**
 * config.php
 * Loads environment variables from .env and defines app-wide constants.
 */

declare(strict_types=1);

// ---- Load .env (simple parser, no external dependency) ----
function load_env(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

load_env(__DIR__ . '/.env');

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

// ---- Database ----
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'job_portal'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// ---- App ----
define('APP_ENV', env('APP_ENV', 'local'));
define('APP_NAME', 'JobPortal');

// ---- File uploads (the one and only sub-folder in this project) ----
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('RESUME_MAX_SIZE', 5 * 1024 * 1024); // 5MB
define('RESUME_ALLOWED_MIME', [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
]);
define('RESUME_ALLOWED_EXT', ['pdf', 'doc', 'docx']);

// ---- Error display ----
if (APP_ENV === 'local') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}

// ---- Session cookie hardening (must run before session_start) ----
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
if (APP_ENV !== 'local') {
    ini_set('session.cookie_secure', '1');
}
