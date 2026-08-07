<?php
/**
 * logout.php
 * Destroys the session. POST-only so a stray link/prefetch can't log a user out.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (!is_post()) {
    redirect('index.php');
}

verify_csrf();
log_out_user();

session_start();
flash('You have been logged out.');
redirect('index.php');
