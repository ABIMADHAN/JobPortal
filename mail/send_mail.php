<?php
/**
 * mail/send_mail.php
 * Thin PHPMailer wrapper used by the app: sendEmail($to, $subject, $htmlBody).
 *
 * Config comes from config.php's env() loader, NOT parse_ini_file(): PHP's INI
 * parser only accepts ';' comments, so it fails outright on the '#' comment in
 * .env and returns false, leaving every SMTP setting empty.
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
// Defines MAIL_LOGO_CID, the content id the templates point their <img> at.
require_once __DIR__ . '/components.php';

/**
 * Plain-text fallback for clients that refuse HTML (and for spam scoring).
 * A table-based email turns into a wall of blank lines under a plain
 * strip_tags(), so block ends become newlines and runs of them collapse.
 */
function html_to_text(string $html): string
{
    // Drop the hidden preheader, <style>/<head> content, and purely decorative
    // text such as the avatar initials (marked data-alt="skip").
    $text = preg_replace('#<(style|head|title)\b[^>]*>.*?</\1>#is', '', $html);
    $text = preg_replace('#<div[^>]*display:none.*?</div>#is', '', (string) $text);
    $text = preg_replace('#<span[^>]*data-alt="skip"[^>]*>.*?</span>#is', '', (string) $text);

    // Cell ends separate label from value; block ends become line breaks.
    $text = preg_replace('#</(td|span)>#i', "\t", (string) $text);
    $text = preg_replace('#<br\s*/?>#i', "\n", (string) $text);
    $text = preg_replace('#</(p|div|tr|h[1-6]|table)>#i', "\n", (string) $text);

    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8');

    // Trim each line (also drops the stray tabs left by empty cells), then
    // collapse runs of blank lines down to one.
    $lines = array_map(
        static fn (string $line): string => trim(preg_replace('/\t+/', "\t", $line)),
        explode("\n", $text)
    );
    $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));

    return trim((string) $text);
}

/**
 * Direct SMTP sending function via PHPMailer.
 * Used internally by the queue worker.
 */
function sendEmailImmediate(string $to, string $subject, string $body): bool
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = (string) env('SMTP_HOST', 'localhost');
        $mail->SMTPAuth = true;
        $mail->Username = (string) env('SMTP_USERNAME');
        $mail->Password = (string) env('SMTP_PASSWORD');
        $mail->SMTPSecure = (string) env('SMTP_ENCRYPTION', PHPMailer::ENCRYPTION_STARTTLS);
        $mail->Port = (int) env('SMTP_PORT', '587');
        // PHPMailer still defaults to ISO-8859-1, which mangles any non-ASCII text.
        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        $mail->setFrom(
            (string) env('MAIL_FROM'),
            (string) env('MAIL_FROM_NAME', APP_NAME)
        );

        $mail->addAddress($to);

        // The logo travels with the message as an inline attachment.
        $logo = __DIR__ . '/logo.png';
        if (is_file($logo)) {
            $mail->addEmbeddedImage($logo, MAIL_LOGO_CID, 'logo.png', 'base64', 'image/png');
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = html_to_text($body);

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mail to ' . $to . ' failed: ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Triggers the queue worker in a non-blocking background process.
 */
function trigger_queue_worker_async(): void
{
    $workerPath = realpath(__DIR__ . '/../queue-worker.php');
    if (!$workerPath) {
        return;
    }

    if (str_starts_with(strtoupper(PHP_OS), 'WIN')) {
        pclose(popen("start /B php " . escapeshellarg($workerPath) . " > NUL 2>&1", "r"));
    } else {
        exec("php " . escapeshellarg($workerPath) . " > /dev/null 2>&1 &");
    }
}

/**
 * Enqueues an email into MySQL `email_queue` table and triggers worker asynchronously.
 */
function enqueue_email(string $to, string $subject, string $body): bool
{
    try {
        require_once __DIR__ . '/../db.php';
        $pdo = get_db();

        $stmt = $pdo->prepare(
            'INSERT INTO email_queue (recipient, subject, body, status, created_at)
             VALUES (:to, :subject, :body, "pending", NOW())'
        );
        $stmt->execute([
            ':to' => $to,
            ':subject' => $subject,
            ':body' => $body,
        ]);

        trigger_queue_worker_async();
        return true;
    } catch (Exception $e) {
        error_log('Failed to enqueue email to ' . $to . ': ' . $e->getMessage());
        return sendEmailImmediate($to, $subject, $body);
    }
}

// Guarded so a preview/test script can define its own sendEmail() first
if (!function_exists('sendEmail')):

/**
 * Primary sendEmail function: enqueues asynchronously for fast HTTP response.
 */
function sendEmail(string $to, string $subject, string $body): bool
{
    return enqueue_email($to, $subject, $body);
}

endif;

