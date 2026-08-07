<?php
/**
 * notifications.php
 * Transactional emails sent to students:
 *
 *   notify_application_received()  a student's application reached a recruiter
 *   notify_application_updated()   a recruiter changed status / interview / notes
 *
 * Both are fire-and-forget: they return a bool, but callers ignore it. A mail
 * server being slow or down must never stop an application from being recorded.
 *
 * The markup lives in mail/components.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mail/components.php';
require_once __DIR__ . '/mail/send_mail.php';

// ---- Status vocabulary ----------------------------------------------------
// status_color() and status_tint() live in mail/components.php with the rest
// of the palette.

/** Headline shown for each status. */
function status_headline(string $status): string
{
    return [
        'applied'      => 'Your application is in',
        'under_review' => 'A recruiter is reviewing your application',
        'shortlisted'  => 'You have been shortlisted',
        'hired'        => 'Congratulations — you got the role',
        'rejected'     => 'An update on your application',
        'withdrawn'    => 'Your application was withdrawn',
    ][$status] ?? 'Your application was updated';
}

/** Friendly one-liner explaining what the status means for the student. */
function status_message(string $status): string
{
    return [
        'applied'      => 'Your application has been received and is waiting to be reviewed.',
        'under_review' => 'Your profile and resume are being read by the hiring team right now.',
        'shortlisted'  => 'The team liked your profile and moved you to the shortlist.',
        'hired'        => 'The company has selected you for this role. They will be in touch with the details.',
        'rejected'     => 'The team has decided not to move forward this time. Keep applying — your next role is out there.',
        'withdrawn'    => 'This application has been withdrawn and is no longer being considered.',
    ][$status] ?? 'Your application status has been updated.';
}

// ---- Data -----------------------------------------------------------------

/**
 * Everything an application email needs, in one query.
 * Returns null when the application no longer exists.
 */
function application_email_context(PDO $pdo, int $applicationId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT a.id, a.status, a.notes, a.interview_at, a.applied_at,
                j.id AS job_id, j.title AS job_title, j.location, j.work_mode, j.job_type,
                c.company_name,
                u.full_name AS student_name, u.email AS student_email
         FROM applications a
         INNER JOIN jobs j ON a.job_id = j.id
         INNER JOIN companies c ON j.company_id = c.id
         INNER JOIN users u ON a.student_id = u.id
         WHERE a.id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $applicationId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/** First name only — "Hi Madhan" reads better than "Hi Madhan G". */
function first_name(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName));
    return $parts[0] ?? $fullName;
}

// ---- The two emails -------------------------------------------------------

/** "We got your application" — sent the moment a student applies. */
function notify_application_received(PDO $pdo, int $applicationId): bool
{
    $ctx = application_email_context($pdo, $applicationId);
    if (!$ctx) {
        return false;
    }

    $content = email_hero(
            'applied',
            'Application received',
            'Your application is in',
            'Hi ' . e(first_name((string) $ctx['student_name'])) . ', your application has been delivered to '
                . '<strong style="color:' . MAIL_TEXT . ';">' . e((string) $ctx['company_name'])
                . '</strong>. Here is a copy for your records.'
        )
        . email_job_card($ctx)
        . email_rows([
            ['Applied on', e(format_datetime($ctx['applied_at']))],
            ['Status', email_badge('Applied', 'applied')],
            ['Reference', '#' . str_pad((string) $ctx['id'], 5, '0', STR_PAD_LEFT)],
        ])
        . email_steps([
            'The recruiter at <strong>' . e((string) $ctx['company_name'])
                . '</strong> reviews your profile and resume.',
            'You get an email the moment your status changes.',
            'If they shortlist you, an interview slot appears on your dashboard.',
        ])
        . email_button('Track my application', app_url('student-dashboard.php'))
        . email_spacer();

    return sendEmail(
        (string) $ctx['student_email'],
        'Application received — ' . $ctx['job_title'] . ' at ' . $ctx['company_name'],
        email_shell(
            'Your application for ' . $ctx['job_title'] . ' at ' . $ctx['company_name'] . ' was submitted successfully.',
            $content
        )
    );
}

/**
 * "Your application was updated" — sent when a recruiter changes the status,
 * sets an interview slot, or leaves a note.
 *
 * @param array<int,string> $changed Which fields changed: status, interview, notes
 */
function notify_application_updated(PDO $pdo, int $applicationId, array $changed): bool
{
    if (!$changed) {
        return false;
    }

    $ctx = application_email_context($pdo, $applicationId);
    if (!$ctx) {
        return false;
    }

    $status = (string) $ctx['status'];
    $interview = $ctx['interview_at'];
    $upcoming = $interview && strtotime((string) $interview) > time();

    // An interview slot is the thing students must act on, so it leads.
    $isInterview = in_array('interview', $changed, true) && $interview;

    $content = email_hero(
            $status,
            $isInterview ? 'Interview scheduled' : status_label($status),
            $isInterview ? 'Your interview is booked' : status_headline($status),
            // Break after the greeting: status_message() is its own capitalised sentence.
            'Hi ' . e(first_name((string) $ctx['student_name'])) . ',<br>' . status_message($status)
        )
        . email_job_card($ctx);

    if ($interview) {
        $content .= email_callout(
            $status,
            $upcoming ? 'Interview scheduled' : 'Interview',
            '<strong style="font-size:17px;">' . e(format_datetime($interview)) . '</strong>'
                . ($upcoming ? '<br>Add it to your calendar and join a few minutes early.' : '')
        );
    }

    $rows = [['Status', email_badge(status_label($status), $status)]];
    if ($interview) {
        $rows[] = ['Interview', e(format_datetime($interview))];
    }
    $rows[] = ['Applied on', e(format_datetime($ctx['applied_at']))];
    $rows[] = ['Reference', '#' . str_pad((string) $ctx['id'], 5, '0', STR_PAD_LEFT)];

    $content .= email_rows($rows);

    if (trim((string) $ctx['notes']) !== '') {
        $content .= email_quote('Message from the recruiter', (string) $ctx['notes']);
    }

    $content .= email_button('Open my dashboard', app_url('student-dashboard.php'), status_color($status))
        . email_spacer();

    $subject = $isInterview
        ? 'Interview scheduled — ' . $ctx['job_title'] . ' at ' . $ctx['company_name']
        : ucfirst(status_label($status)) . ' — ' . $ctx['job_title'] . ' at ' . $ctx['company_name'];

    return sendEmail(
        (string) $ctx['student_email'],
        $subject,
        email_shell(
            $isInterview
                ? 'Your interview for ' . $ctx['job_title'] . ' is on ' . format_datetime($interview) . '.'
                : 'Your application for ' . $ctx['job_title'] . ' at ' . $ctx['company_name']
                    . ' is now ' . status_label($status) . '.',
            $content
        )
    );
}
