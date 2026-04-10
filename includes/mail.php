<?php
/**
 * Email helper functions.
 *
 * Uses PHP's built-in mail() function by default.
 * For production, configure an SMTP library or replace send_mail() accordingly.
 */

require_once __DIR__ . '/../config.php';

/**
 * Send an email.
 *
 * @return bool True if the mail was accepted for delivery.
 */
function send_mail(string $toAddress, string $toName, string $subject, string $htmlBody): bool
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>',
        'Reply-To: ' . MAIL_FROM_ADDRESS,
        'X-Mailer: PHP/' . phpversion(),
    ];

    $to = $toName ? "$toName <$toAddress>" : $toAddress;

    return mail($to, $subject, $htmlBody, implode("\r\n", $headers));
}

/**
 * Send a signup confirmation email.
 */
function send_signup_confirmation(array $user, array $event, array $functionInfo, array $period): bool
{
    $subject = 'Signup Confirmation: ' . $event['title'];

    $date = date('l, F j, Y', strtotime($event['event_date']));
    $start = date('g:i A', strtotime($period['start_time']));
    $end   = date('g:i A', strtotime($period['end_time']));

    $html = '<!DOCTYPE html><html><body>';
    $html .= '<h2>Signup Confirmation</h2>';
    $html .= '<p>Hello ' . htmlspecialchars($user['username']) . ',</p>';
    $html .= '<p>You have been signed up for the following:</p>';
    $html .= '<table border="1" cellpadding="8" cellspacing="0">';
    $html .= '<tr><td><strong>Event</strong></td><td>' . htmlspecialchars($event['title']) . '</td></tr>';
    $html .= '<tr><td><strong>Date</strong></td><td>' . $date . '</td></tr>';
    $html .= '<tr><td><strong>Location</strong></td><td>' . htmlspecialchars($event['location']) . '</td></tr>';
    $html .= '<tr><td><strong>Function</strong></td><td>' . htmlspecialchars($functionInfo['function_name']) . '</td></tr>';
    $html .= '<tr><td><strong>Time</strong></td><td>' . $start . ' &ndash; ' . $end . '</td></tr>';
    $html .= '</table>';
    $html .= '<p>If you need to cancel, please log in to the application.</p>';
    $html .= '<p>Thank you!</p>';
    $html .= '</body></html>';

    return send_mail($user['email'], $user['username'], $subject, $html);
}

/**
 * Send a reminder email for an upcoming signup.
 */
function send_signup_reminder(array $user, array $event, array $functionInfo, array $period): bool
{
    $subject = 'Reminder: ' . $event['title'] . ' is coming up!';

    $date  = date('l, F j, Y', strtotime($event['event_date']));
    $start = date('g:i A', strtotime($period['start_time']));
    $end   = date('g:i A', strtotime($period['end_time']));

    $html = '<!DOCTYPE html><html><body>';
    $html .= '<h2>Event Reminder</h2>';
    $html .= '<p>Hello ' . htmlspecialchars($user['username']) . ',</p>';
    $html .= '<p>This is a reminder that you are signed up for:</p>';
    $html .= '<table border="1" cellpadding="8" cellspacing="0">';
    $html .= '<tr><td><strong>Event</strong></td><td>' . htmlspecialchars($event['title']) . '</td></tr>';
    $html .= '<tr><td><strong>Date</strong></td><td>' . $date . '</td></tr>';
    $html .= '<tr><td><strong>Location</strong></td><td>' . htmlspecialchars($event['location']) . '</td></tr>';
    $html .= '<tr><td><strong>Function</strong></td><td>' . htmlspecialchars($functionInfo['function_name']) . '</td></tr>';
    $html .= '<tr><td><strong>Time</strong></td><td>' . $start . ' &ndash; ' . $end . '</td></tr>';
    $html .= '</table>';
    $html .= '<p>We look forward to seeing you there!</p>';
    $html .= '</body></html>';

    return send_mail($user['email'], $user['username'], $subject, $html);
}
