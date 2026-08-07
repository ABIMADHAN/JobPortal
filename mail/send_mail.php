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

// Guarded so a preview/test script can define its own sendEmail() first and
// capture the rendered HTML instead of contacting an SMTP server.
if (!function_exists('sendEmail')):

function sendEmail(string $to, string $subject, string $body): bool
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

        // The logo travels with the message as an inline attachment. Gmail and
        // Outlook.com both refuse data: URIs in <img>, so cid: is the only
        // embedding that renders everywhere.
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
        // Echoing SMTP internals would leak credentials-adjacent detail into the
        // page and corrupt any JSON/redirect response. Log it instead.
        error_log('Mail to ' . $to . ' failed: ' . $mail->ErrorInfo);
        return false;
    }
}

endif;
