<?php
/**
 * email_template.php — the HTML e-mail every notification is sent as.
 *
 * Built for Gmail first, which is where the students read it:
 *   - solid colours only, repeated as bgcolor attributes: Gmail drops CSS
 *     gradients, filters and box shadows, and rgba() backgrounds go with them,
 *     which is what left the old dark design's grey text unreadable;
 *   - a light card with dark text, so every line clears WCAG contrast and
 *     Gmail's dark mode inverts it cleanly instead of muddying it;
 *   - one fluid 600px column with a <style> media query (Gmail honours it) that
 *     tightens the padding and stacks the detail rows on a phone;
 *   - a per-message reference in the footer, so Gmail never folds the header
 *     and footer away behind "•••" as text it has already shown in the thread.
 *
 * The combined MCNP+ISAP seal is embedded by sendEmail() as cid:logo_img.
 *
 * @param string $student_name   The student's full name
 * @param string $message        The main message/content
 * @param string $type           info | success | warning | error
 * @param string $subject        The e-mail subject line
 * @param string $registrar_name (Optional) Name of the registrar who sent it
 * @param string $institution    (Optional) ISAP, MCNP, ...
 * @param string $variant        (Optional) 'announcement' for a registrar broadcast
 * @return string                Complete HTML e-mail body
 */
function buildEmailTemplate($student_name, $message, $type = 'info', $subject = 'New Notification', $registrar_name = '', $institution = '', $variant = '') {

    // Solid colours only (see above): the badge's text colour and its fill.
    $accents = [
        'info'    => ['color' => '#1d4ed8', 'bg' => '#dbeafe', 'label' => 'Information'],
        'success' => ['color' => '#047857', 'bg' => '#d1fae5', 'label' => 'Approved'],
        'warning' => ['color' => '#b45309', 'bg' => '#fef3c7', 'label' => 'Action Required'],
        'error'   => ['color' => '#b91c1c', 'bg' => '#fee2e2', 'label' => 'Alert'],
    ];
    $accent = $accents[$type] ?? $accents['info'];

    $isAnnouncement = ($variant === 'announcement');
    if ($isAnnouncement) {
        $accent = ['color' => '#92400e', 'bg' => '#fef3c7', 'label' => 'Announcement'];
    }
    $accentColor = $accent['color'];
    $accentBg    = $accent['bg'];
    $label       = $accent['label'];

    // Philippine time whatever the caller set: PHP defaults to UTC, which put
    // every e-mail's date eight hours behind.
    $now           = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $year          = $now->format('Y');
    $dateFormatted = $now->format('F j, Y \a\t g:i A');
    // Differs on every message, so Gmail treats the footer as new text.
    $reference = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

    $safeMessage     = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $safeName        = htmlspecialchars($student_name ?: 'Student', ENT_QUOTES, 'UTF-8');
    $safeSubject     = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $safeRegistrar   = htmlspecialchars($registrar_name ?: 'Registrar Office', ENT_QUOTES, 'UTF-8');
    $safeInstitution = htmlspecialchars($institution ?: 'MCNP-ISAP', ENT_QUOTES, 'UTF-8');

    // The heading: an announcement reads as one, whatever the subject says.
    $heading = $isAnnouncement ? 'Announcement' : $safeSubject;
    $kicker  = $isAnnouncement ? 'From the Office of the Registrar' : 'Registrar Office &mdash; ' . $safeInstitution;

    // The message itself: an announcement is set apart in a gold-ruled panel so
    // it is the first thing read; anything else is an ordinary paragraph.
    if ($isAnnouncement) {
        $messageBlock = <<<HTML
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 24px;">
                                <tr>
                                    <td bgcolor="#fffbeb" style="background-color: #fffbeb; border: 1px solid #fde68a; border-left: 5px solid #c9a227; border-radius: 10px; padding: 20px 22px;">
                                        <p class="msg" style="margin: 0; font-size: 16px; line-height: 1.7; color: #1f2937; word-break: break-word;">{$safeMessage}</p>
                                    </td>
                                </tr>
                            </table>
HTML;
    } else {
        $messageBlock = <<<HTML
                            <p class="msg" style="margin: 0 0 24px; font-size: 16px; line-height: 1.7; color: #1f2937; word-break: break-word;">{$safeMessage}</p>
HTML;
    }

    // One row of the details table. The label/value pair stacks on a phone.
    $row = function ($labelText, $valueHtml, $last = false) {
        $border = $last ? '' : 'border-bottom: 1px solid #e5e7eb;';
        return <<<HTML
                                <tr>
                                    <td class="dl" width="130" valign="top" style="{$border} padding: 12px 16px; font-size: 12px; font-weight: 700; color: #4b5563; text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap;">{$labelText}</td>
                                    <td class="dd" valign="top" style="{$border} padding: 12px 16px; font-size: 15px; font-weight: 600; color: #111827; word-break: break-word;">{$valueHtml}</td>
                                </tr>
HTML;
    };
    $badge = "<span style=\"display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 700; background-color: {$accentBg}; color: {$accentColor};\">{$label}</span>";
    $details = $row('Recipient', $safeName)
             . $row('Type', $badge)
             . $row('Date', $dateFormatted)
             . $row('From', $safeRegistrar, true);

    return <<<HTML
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{$safeSubject}</title>
    <style>
        /* Phones: less padding, larger text, and the detail rows stacked. */
        @media only screen and (max-width: 600px) {
            .outer   { padding: 12px 8px !important; }
            .pad     { padding: 24px 18px !important; }
            .head    { padding: 26px 18px 22px !important; }
            .title   { font-size: 22px !important; }
            .msg     { font-size: 16px !important; }
            .dl, .dd { display: block !important; width: auto !important; }
            .dl      { padding: 12px 14px 2px !important; border-bottom: none !important; }
            .dd      { padding: 0 14px 12px !important; }
        }
    </style>
    <!--[if mso]>
    <style type="text/css">
        table { border-collapse: collapse; }
        body, td, p { font-family: Arial, sans-serif !important; }
    </style>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; background-color: #eef2f7; font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; -webkit-text-size-adjust: 100%;">

    <!-- Preheader: the line Gmail shows beside the subject in the inbox. -->
    <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">{$label} from the {$safeInstitution} Registrar's Office &mdash; {$dateFormatted}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef2f7" style="background-color: #eef2f7;">
        <tr>
            <td class="outer" align="center" style="padding: 28px 12px;">

                <!-- Card -->
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="max-width: 600px; background-color: #ffffff; border: 1px solid #dbe2ea; border-radius: 14px; overflow: hidden;">

                    <!-- Header: navy, with the seal on a white disc so its transparent
                         edges read cleanly on any background, dark mode included. -->
                    <tr>
                        <td class="head" align="center" bgcolor="#1a365d" style="background-color: #1a365d; padding: 30px 24px 26px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" bgcolor="#ffffff" width="104" height="104" style="background-color: #ffffff; width: 104px; height: 104px; border-radius: 52px; border: 3px solid #c9a227; text-align: center; vertical-align: middle;">
                                        <img src="cid:logo_img" alt="MCNP &amp; ISAP" width="84" height="80" style="display: block; margin: 0 auto; width: 84px; height: 80px; border: 0; outline: none;">
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 16px 0 0; font-size: 12px; font-weight: 700; color: #f3d77a; text-transform: uppercase; letter-spacing: 0.14em;">{$kicker}</p>
                            <h1 class="title" style="margin: 6px 0 0; font-size: 24px; line-height: 1.3; font-weight: 800; color: #ffffff; word-break: break-word;">{$heading}</h1>
                            <p style="margin: 6px 0 0; font-size: 13px; color: #d6e0ee;">Medical Colleges of Northern Philippines &middot; International School of Asia and the Pacific</p>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td class="pad" style="padding: 30px 28px;">

                            <p style="margin: 0 0 16px; font-size: 17px; font-weight: 700; color: #111827;">Dear {$safeName},</p>

{$messageBlock}

                            <!-- Details -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f8fafc" style="background-color: #f8fafc; border: 1px solid #e5e7eb; border-radius: 10px; margin: 0 0 24px;">
{$details}
                            </table>

                            <!-- Note -->
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 24px;">
                                <tr>
                                    <td bgcolor="#eff6ff" style="background-color: #eff6ff; border-left: 4px solid #1a365d; border-radius: 0 8px 8px 0; padding: 14px 18px;">
                                        <p style="margin: 0 0 4px; font-size: 14px; font-weight: 700; color: #1a365d;">Please take note.</p>
                                        <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #374151;">If you have any questions or need assistance, please visit the Registrar's Office. We are here to help you with your academic journey.</p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; padding-top: 18px; border-top: 1px solid #e5e7eb; font-size: 14px; line-height: 1.6; color: #374151;">
                                Best regards,<br>
                                <strong style="color: #111827;">{$safeRegistrar}</strong><br>
                                {$safeInstitution} Registrar Office
                            </p>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" bgcolor="#f1f5f9" style="background-color: #f1f5f9; border-top: 1px solid #e5e7eb; padding: 20px 24px;">
                            <p style="margin: 0 0 6px; font-size: 14px; font-weight: 700; color: #1f2937;">{$safeInstitution} Registrar Portal</p>
                            <p style="margin: 0; font-size: 13px; line-height: 1.6; color: #4b5563;">This is an automated message. Please do not reply directly to this e-mail.<br>Contact the Registrar's Office for any concerns.</p>
                            <p style="margin: 10px 0 0; font-size: 12px; color: #4b5563;">Sent {$dateFormatted} &middot; Ref. {$reference}</p>
                            <p style="margin: 4px 0 0; font-size: 12px; color: #4b5563;">&copy; {$year} {$safeInstitution}. All rights reserved.</p>
                        </td>
                    </tr>

                </table>
                <!-- End card -->

            </td>
        </tr>
    </table>

</body>
</html>
HTML;
}
