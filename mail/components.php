<?php
/**
 * mail/components.php
 * Building blocks for the transactional emails, styled to match the
 * CareerStudio site (see site.css) as closely as email clients allow.
 *
 * Three rules shape everything here:
 *
 *  1. Tables + inline styles. Outlook renders HTML through Word (no flexbox,
 *     no grid, no float) and Gmail strips most of a <style> block.
 *  2. Solid colours only. Word ignores 8-digit hex (#4648d414) and rgba(),
 *     so every tint is a real hex value.
 *  3. Light theme, always. Clients with dark mode either respect the
 *     colour-scheme declaration or get their inversion undone by the
 *     overrides in email_dark_mode_css().
 */

declare(strict_types=1);

require_once __DIR__ . '/../functions.php';

// ---- Palette: the site.css custom properties, resolved (emails have no vars) ----
const MAIL_BG        = '#eef1f6';  // page behind the card, a shade under --bg-surface
const MAIL_SURFACE   = '#ffffff';  // --surface-container-lowest
const MAIL_SOFT      = '#f7f9fb';  // --bg-surface
const MAIL_CHIP      = '#eceef0';  // --surface-container
const MAIL_INK       = '#0F172A';  // --dark-btn, the brand wordmark
const MAIL_TEXT      = '#191c1e';  // --text-on-surface
const MAIL_MUTED     = '#45464d';  // --text-on-surface-variant
const MAIL_FAINT     = '#8a8d93';
const MAIL_LINE      = '#c6c6cd';  // --outline-variant
const MAIL_HAIRLINE  = '#e4e7ec';
const MAIL_ACCENT    = '#4648d4';  // --secondary
const MAIL_ACCENT_BG = '#ececfb';  // solid stand-in for rgba(70,72,212,0.08)

/** Content id of the inline logo attached by sendEmail(). */
const MAIL_LOGO_CID = 'careerstudio-logo';

/** Manrope for headings / Inter for body, mirroring the site's type pairing. */
const MAIL_FONT      = "'Inter','Segoe UI',Roboto,Helvetica,Arial,sans-serif";
const MAIL_FONT_HEAD = "'Manrope','Segoe UI',Roboto,Helvetica,Arial,sans-serif";

// ---- Status colours -------------------------------------------------------

/** Strong accent per status: buttons, callout bars, eyebrow text. */
function status_color(string $status): string
{
    return [
        'applied'      => '#4648d4',
        'under_review' => '#B45309',
        'shortlisted'  => '#0369A1',
        'hired'        => '#047857',
        'rejected'     => '#B91C1C',
        'withdrawn'    => '#475569',
    ][$status] ?? MAIL_ACCENT;
}

/** Solid light background behind that accent — no alpha, so Word keeps it. */
function status_tint(string $status): string
{
    return [
        'applied'      => '#ececfb',
        'under_review' => '#fef3c7',
        'shortlisted'  => '#e0f2fe',
        'hired'        => '#d1fae5',
        'rejected'     => '#fee2e2',
        'withdrawn'    => '#eef2f6',
    ][$status] ?? MAIL_ACCENT_BG;
}

// ---- Shell ----------------------------------------------------------------

/**
 * Re-asserts every colour after a client has flipped the email to dark.
 *
 * Apple Mail and iOS honour prefers-color-scheme; Outlook.com rewrites the
 * markup and prefixes it with [data-ogsc] (text) / [data-ogsb] (background),
 * so the same declarations are repeated under those selectors.
 */
function email_dark_mode_css(): string
{
    $rules = [
        '.e-bg'      => 'background-color:' . MAIL_BG . '!important;',
        '.e-surface' => 'background-color:' . MAIL_SURFACE . '!important;',
        '.e-soft'    => 'background-color:' . MAIL_SOFT . '!important;',
        '.e-ink'     => 'color:' . MAIL_INK . '!important;',
        '.e-title'   => 'color:' . MAIL_TEXT . '!important;',
        '.e-text'    => 'color:' . MAIL_TEXT . '!important;',
        '.e-muted'   => 'color:' . MAIL_MUTED . '!important;',
        '.e-muted a' => 'color:' . MAIL_MUTED . '!important;',
        '.e-faint'   => 'color:' . MAIL_FAINT . '!important;',
        '.e-chip'    => 'background-color:' . MAIL_CHIP . '!important;color:' . MAIL_MUTED . '!important;',
        '.e-btn a'   => 'color:#ffffff!important;',
        '.e-avatar td' => 'color:#ffffff!important;',
    ];

    // One rule per status so badges, eyebrows and callouts keep their hue.
    foreach (['applied', 'under_review', 'shortlisted', 'hired', 'rejected', 'withdrawn'] as $status) {
        $rules['.e-s-' . str_replace('_', '-', $status)] =
            'background-color:' . status_tint($status) . '!important;'
            . 'color:' . status_color($status) . '!important;';
    }

    /** Emits every rule, optionally under a client-specific ancestor selector. */
    $render = static function (string $prefix) use ($rules): string {
        $css = '';
        foreach ($rules as $selector => $declarations) {
            $css .= ($prefix === '' ? '' : $prefix . ' ') . $selector . '{' . $declarations . '}';
        }
        return $css;
    };

    return '@media (prefers-color-scheme:dark){' . $render('') . '}'
        . $render('[data-ogsc]')
        . $render('[data-ogsb]');
}

/** Outer document: preheader, brand bar, content, footer. */
function email_shell(string $preheader, string $content): string
{
    return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"'
        . ' "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
        . '<html xmlns="http://www.w3.org/1999/xhtml"><head>'
        . '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0" />'
        // Declaring light-only is what stops most clients inverting in the first place.
        . '<meta name="color-scheme" content="light" />'
        . '<meta name="supported-color-schemes" content="light" />'
        . '<title>' . e(APP_NAME) . '</title>'
        . '<style>'
        . ':root{color-scheme:light;supported-color-schemes:light;}'
        // Real webfonts for the clients that allow them; the stacks cover the rest.
        . "@import url('https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600&display=swap');"
        . '@media only screen and (max-width:620px){'
        . '.wrap{width:100%!important;}'
        . '.pad{padding-left:22px!important;padding-right:22px!important;}'
        . '.h1{font-size:26px!important;line-height:34px!important;}'
        . '.stack{display:block!important;width:100%!important;padding-bottom:0!important;}'
        . '.stack-v{padding-top:2px!important;padding-bottom:12px!important;}'
        . '.btn a{display:block!important;}'
        . '}'
        . email_dark_mode_css()
        . '</style></head>'

        . '<body class="e-bg" bgcolor="' . MAIL_BG . '" style="margin:0;padding:0;'
        . 'background-color:' . MAIL_BG . ';-webkit-font-smoothing:antialiased;">'

        // Inbox preview line, hidden inside the message itself.
        . '<div style="display:none;font-size:1px;color:' . MAIL_BG . ';line-height:1px;'
        . 'max-height:0;max-width:0;opacity:0;overflow:hidden;">' . e($preheader)
        . str_repeat('&#847;&zwnj;&nbsp;', 30) . '</div>'

        . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"'
        . ' class="e-bg" bgcolor="' . MAIL_BG . '" style="background-color:' . MAIL_BG . ';">'
        . '<tr><td align="center" style="padding:32px 12px 40px;">'

        . '<table role="presentation" class="wrap" border="0" cellpadding="0" cellspacing="0" width="600"'
        . ' style="width:600px;max-width:600px;font-family:' . MAIL_FONT . ';">'

        . email_header()
        // Side borders continue the outline the header and footer start and end.
        . '<tr><td class="e-surface" bgcolor="' . MAIL_SURFACE . '" style="background-color:' . MAIL_SURFACE . ';'
        . 'border-left:1px solid ' . MAIL_LINE . ';border-right:1px solid ' . MAIL_LINE . ';">'
        . $content . '</td></tr>'
        . email_footer()

        . '</table></td></tr></table></body></html>';
}

/**
 * Brand bar: the same lockup as the site navbar — briefcase mark plus the
 * CareerStudio wordmark on the light surface, over a hairline rule.
 */
function email_header(): string
{
    return '<tr><td class="pad e-soft" bgcolor="' . MAIL_SOFT . '" style="background-color:' . MAIL_SOFT . ';'
        . 'padding:24px 32px;border:1px solid ' . MAIL_LINE . ';border-bottom:0;border-radius:16px 16px 0 0;">'

        . '<table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="26" valign="middle" style="padding-right:9px;">'
        . '<img src="cid:' . MAIL_LOGO_CID . '" width="24" height="24" alt=""'
        . ' style="display:block;width:24px;height:24px;border:0;" />'
        . '</td>'
        . '<td class="e-ink" valign="middle" style="font-family:' . MAIL_FONT_HEAD . ';color:' . MAIL_INK . ';'
        . 'font-size:22px;font-weight:800;letter-spacing:-0.4px;line-height:26px;">'
        . e(APP_NAME) . '</td>'
        . '</tr></table></td></tr>';
}

/** Footer mirroring site-footer.php: links, reason for receipt, tagline. */
function email_footer(): string
{
    $link = 'color:' . MAIL_MUTED . ';text-decoration:none;font-weight:600;';
    $sep = '<span style="color:' . MAIL_LINE . ';padding:0 9px;">&middot;</span>';

    return '<tr><td class="pad e-soft" bgcolor="' . MAIL_SOFT . '" style="background-color:' . MAIL_SOFT . ';'
        . 'border:1px solid ' . MAIL_LINE . ';border-top:0;border-radius:0 0 16px 16px;padding:24px 32px 28px;">'

        . '<div class="e-muted" style="font-size:13px;line-height:20px;color:' . MAIL_MUTED . ';">'
        . '<a href="' . e(app_url('jobs.php')) . '" style="' . $link . '">Browse Jobs</a>' . $sep
        . '<a href="' . e(app_url('internships.php')) . '" style="' . $link . '">Internships</a>' . $sep
        . '<a href="' . e(app_url('companies.php')) . '" style="' . $link . '">Companies</a>' . $sep
        . '<a href="' . e(app_url('student-dashboard.php')) . '" style="' . $link . '">My Dashboard</a>'
        . '</div>'

        . '<p class="e-faint" style="margin:16px 0 0;font-size:12px;line-height:18px;color:' . MAIL_FAINT . ';">'
        . 'You are receiving this because you applied for a role on ' . e(APP_NAME) . '. '
        . 'This mailbox is not monitored — please reply through the portal.'
        . '</p>'

        . '<p class="e-faint" style="margin:10px 0 0;font-size:12px;line-height:18px;color:' . MAIL_FAINT . ';">'
        . '&copy; ' . date('Y') . ' ' . e(APP_NAME) . ' AI. Precision in Professional Growth.'
        . '</p>'

        . '</td></tr>';
}

// ---- Content blocks -------------------------------------------------------

/** Eyebrow pill + Manrope headline + supporting line, as on the site heroes. */
function email_hero(string $status, string $eyebrow, string $heading, string $sub): string
{
    $color = status_color($status);
    $tint = status_tint($status);
    $cls = 'e-s-' . str_replace('_', '-', $status);

    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">'
        . '<tr><td class="pad" style="padding:36px 32px 0;">'

        . '<table role="presentation" border="0" cellpadding="0" cellspacing="0"><tr>'
        . '<td class="' . $cls . '" bgcolor="' . $tint . '" style="background-color:' . $tint . ';'
        . 'border-radius:99px;padding:6px 14px;font-family:' . MAIL_FONT . ';font-size:12px;'
        . 'font-weight:700;letter-spacing:0.6px;color:' . $color . ';">'
        . e(strtoupper($eyebrow)) . '</td></tr></table>'

        . '<h1 class="h1 e-title" style="margin:18px 0 0;font-family:' . MAIL_FONT_HEAD . ';font-size:30px;'
        . 'line-height:38px;font-weight:800;letter-spacing:-0.6px;color:' . MAIL_TEXT . ';">'
        . e($heading) . '</h1>'

        . '<p class="e-muted" style="margin:12px 0 0;font-size:16px;line-height:26px;color:' . MAIL_MUTED . ';">'
        . $sub . '</p>'

        . '</td></tr></table>';
}

/** The role: company avatar tile, title, and meta chips — the site's card look. */
function email_job_card(array $ctx): string
{
    $avatar = avatar_color((string) $ctx['company_name']);

    $chips = '';
    foreach ([$ctx['work_mode'], $ctx['job_type'], $ctx['location'] ?: null] as $chip) {
        if (!$chip) {
            continue;
        }
        $chips .= '<span class="e-chip" style="display:inline-block;background-color:' . MAIL_CHIP . ';'
            . 'color:' . MAIL_MUTED . ';font-size:12px;font-weight:600;padding:5px 11px;'
            . 'border-radius:6px;margin:10px 6px 0 0;">' . e((string) $chip) . '</span>';
    }

    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">'
        . '<tr><td class="pad" style="padding:26px 32px 0;">'

        . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"'
        . ' class="e-surface" bgcolor="' . MAIL_SURFACE . '" style="background-color:' . MAIL_SURFACE . ';'
        . 'border:1px solid ' . MAIL_LINE . ';border-radius:16px;">'
        . '<tr><td style="padding:20px;">'

        . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>'
        . '<td width="48" valign="top" style="padding-right:14px;">'
        . '<table role="presentation" class="e-avatar" border="0" cellpadding="0" cellspacing="0" width="48"'
        . ' bgcolor="' . $avatar . '" style="width:48px;height:48px;background-color:' . $avatar . ';'
        . 'border-radius:14px;">'
        . '<tr><td align="center" style="font-family:' . MAIL_FONT_HEAD . ';color:#ffffff;'
        . 'font-size:20px;font-weight:800;line-height:48px;">'
        . '<span data-alt="skip">' . e(initials((string) $ctx['company_name'])) . '</span>'
        . '</td></tr></table></td>'

        . '<td valign="top">'
        . '<div class="e-text" style="font-family:' . MAIL_FONT_HEAD . ';font-size:17px;font-weight:700;'
        . 'color:' . MAIL_TEXT . ';line-height:24px;">' . e((string) $ctx['job_title']) . '</div>'
        . '<div class="e-muted" style="font-size:14px;color:' . MAIL_MUTED . ';padding-top:3px;">'
        . e((string) $ctx['company_name']) . '</div>'
        . '<div>' . $chips . '</div>'
        . '</td></tr></table>'

        . '</td></tr></table></td></tr></table>';
}

/**
 * Label/value detail rows.
 *
 * @param array<int, array{0:string, 1:string}> $rows
 */
function email_rows(array $rows): string
{
    $html = '';
    $last = count($rows) - 1;

    foreach (array_values($rows) as $i => [$label, $value]) {
        $border = $i === $last ? '' : 'border-bottom:1px solid ' . MAIL_HAIRLINE . ';';
        $html .= '<tr>'
            . '<td class="stack e-faint" width="170" valign="top" style="width:170px;padding:14px 0;' . $border
            . 'font-size:11px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;'
            . 'color:' . MAIL_FAINT . ';">' . e($label) . '</td>'
            . '<td class="stack stack-v e-text" valign="top" style="padding:14px 0;' . $border
            . 'font-size:15px;line-height:22px;color:' . MAIL_TEXT . ';font-weight:600;">' . $value . '</td>'
            . '</tr>';
    }

    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">'
        . '<tr><td class="pad" style="padding:10px 32px 0;">'
        . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">'
        . $html . '</table></td></tr></table>';
}

/** Tinted box for the thing that matters most (the interview slot). */
function email_callout(string $status, string $title, string $body): string
{
    $color = status_color($status);
    $tint = status_tint($status);
    $cls = 'e-s-' . str_replace('_', '-', $status);

    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">'
        . '<tr><td class="pad" style="padding:24px 32px 0;">'
        . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"'
        . ' class="' . $cls . '" bgcolor="' . $tint . '" style="background-color:' . $tint . ';'
        . 'border-left:4px solid ' . $color . ';border-radius:12px;">'
        . '<tr><td style="padding:18px 20px;">'
        . '<div style="font-size:11px;font-weight:700;letter-spacing:0.8px;text-transform:uppercase;'
        . 'color:' . $color . ';">' . e($title) . '</div>'
        . '<div class="e-text" style="font-size:15px;line-height:24px;color:' . MAIL_TEXT . ';'
        . 'padding-top:7px;">' . $body . '</div>'
        . '</td></tr></table></td></tr></table>';
}

/** Bulletproof button — a table, so Outlook renders the background. */
function email_button(string $text, string $url, string $color = MAIL_ACCENT): string
{
    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">'
        . '<tr><td class="pad" style="padding:30px 32px 4px;">'
        . '<table role="presentation" class="btn e-btn" border="0" cellpadding="0" cellspacing="0">'
        . '<tr><td align="center" bgcolor="' . $color . '" style="background-color:' . $color . ';'
        . 'border-radius:12px;">'
        . '<a href="' . e($url) . '" target="_blank" style="display:inline-block;padding:14px 30px;'
        . 'font-family:' . MAIL_FONT . ';font-size:14px;font-weight:600;color:#ffffff;'
        . 'text-decoration:none;border-radius:12px;">' . e($text) . '</a>'
        . '</td></tr></table></td></tr></table>';
}

/** Status pill, same shape as the site's chips. */
function email_badge(string $text, string $status): string
{
    $color = status_color($status);
    $tint = status_tint($status);
    $cls = 'e-s-' . str_replace('_', '-', $status);

    return '<span class="' . $cls . '" style="display:inline-block;background-color:' . $tint . ';'
        . 'color:' . $color . ';font-size:13px;font-weight:700;padding:5px 12px;border-radius:6px;'
        . 'text-transform:capitalize;">' . e($text) . '</span>';
}

/** Quoted block for free text the recruiter typed. */
function email_quote(string $title, string $text): string
{
    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">'
        . '<tr><td class="pad" style="padding:24px 32px 0;">'
        . '<div class="e-faint" style="font-size:11px;font-weight:700;letter-spacing:0.8px;'
        . 'text-transform:uppercase;color:' . MAIL_FAINT . ';padding-bottom:9px;">' . e($title) . '</div>'
        . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"'
        . ' class="e-soft" bgcolor="' . MAIL_SOFT . '" style="background-color:' . MAIL_SOFT . ';'
        . 'border:1px solid ' . MAIL_HAIRLINE . ';border-radius:12px;">'
        . '<tr><td class="e-text" style="padding:16px 18px;font-size:15px;line-height:24px;'
        . 'color:' . MAIL_TEXT . ';font-style:italic;">&ldquo;' . nl2br(e($text)) . '&rdquo;</td></tr>'
        . '</table></td></tr></table>';
}

/** Numbered "what happens next" list. */
function email_steps(array $steps): string
{
    $html = '';
    foreach (array_values($steps) as $i => $step) {
        $html .= '<tr>'
            . '<td width="28" valign="top" style="padding:0 12px 14px 0;">'
            . '<table role="presentation" class="e-s-applied" border="0" cellpadding="0" cellspacing="0"'
            . ' width="26" bgcolor="' . MAIL_ACCENT_BG . '" style="width:26px;height:26px;'
            . 'background-color:' . MAIL_ACCENT_BG . ';border-radius:50%;">'
            . '<tr><td align="center" style="font-family:' . MAIL_FONT_HEAD . ';font-size:12px;'
            . 'font-weight:800;color:' . MAIL_ACCENT . ';line-height:26px;">' . ($i + 1) . '</td></tr>'
            . '</table></td>'
            . '<td class="e-muted" valign="top" style="padding-bottom:14px;font-size:15px;'
            . 'line-height:24px;color:' . MAIL_MUTED . ';">' . $step . '</td>'
            . '</tr>';
    }

    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">'
        . '<tr><td class="pad" style="padding:28px 32px 0;">'
        . '<div class="e-faint" style="font-size:11px;font-weight:700;letter-spacing:0.8px;'
        . 'text-transform:uppercase;color:' . MAIL_FAINT . ';padding-bottom:16px;">What happens next</div>'
        . '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">'
        . $html . '</table></td></tr></table>';
}

/** Bottom spacer so content never touches the footer rule. */
function email_spacer(int $height = 34): string
{
    return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">'
        . '<tr><td style="height:' . $height . 'px;line-height:' . $height . 'px;font-size:0;">&nbsp;</td></tr></table>';
}
