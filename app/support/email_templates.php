<?php declare(strict_types=1);

/**
 * Email templates for Plainfully (HTML + plain text builders).
 * Keep logic here so controllers stay clean.
 */

function pf_email_logo_html(): string
{
    $logoUrl = 'https://plainfully.com/assets/img/plainfully-logo-light.256.png';

    return
        '<div style="margin:0 0 16px 0;text-align:left;">'
        . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'alt="Plainfully" '
        . 'width="48" height="48" '
        . 'style="display:block;border:0;outline:none;text-decoration:none;">'
        . '</div>';
}


function pf_email_badge_html(string $label): string
{
    $safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    // Simple badge (no external CSS)
    return '<div style="display:inline-block;padding:8px 12px;border-radius:999px;background:#111827;color:#ffffff;font-weight:700;font-size:13px;">'
        . $safe
        . '</div>';
}

/**
 * Build “check result” email body (inner HTML only).
 * Keep it dumb and predictable.
 */
function pf_email_check_inner_html(
    string $mode,              // 'scamcheck' | 'clarify'
    string $shortVerdict,
    string $inputCapsule,
    string $viewUrl,
    ?string $metaLine = null    // e.g. "Plan: Bronze · 2/3 used"
): string {
    // ---- Safety: escape everything that came from outside ----
    $safeVerdict = htmlspecialchars($shortVerdict, ENT_QUOTES, 'UTF-8');
    $safeCapsule = nl2br(htmlspecialchars($inputCapsule, ENT_QUOTES, 'UTF-8'));
    $safeUrl     = htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8');

    // ---- Brand palette (matches your LIGHT email wrapper) ----
    $text   = '#111827';
    $muted  = '#6B7280';
    $border = '#E5E7EB';

    $brand  = '#2C6F63'; // Plainfully deep teal
    $brand2 = '#5ABFA8'; // Plainfully soft teal (for subtle fills)

    $headline = ($mode === 'scamcheck') ? 'ScamCheck result' : 'Clarification result';

    $intro = ($mode === 'scamcheck')
        ? 'We’ve checked the message you forwarded.'
        : 'Here’s the simplest explanation of what your message means.';

    $metaHtml = '';
    if ($metaLine !== null && trim($metaLine) !== '') {
        $metaHtml =
            '<p style="margin:10px 0 0;color:' . $muted . ';font-size:13px;line-height:1.4;">'
            . htmlspecialchars($metaLine, ENT_QUOTES, 'UTF-8')
            . '</p>';
    }

    // Calm badge (teal outline + soft fill)
    $badge =
        '<div style="display:inline-block;padding:8px 12px;border-radius:999px;'
        . 'background:rgba(90,191,168,0.18);'
        . 'border:1px solid rgba(44,111,99,0.35);'
        . 'color:' . $brand . ';font-weight:700;font-size:13px;line-height:1;">'
        . $safeVerdict
        . '</div>';

    // CTA button (teal)
    $button =
        '<p style="margin:18px 0 0;">'
        . '<a href="' . $safeUrl . '" '
        . 'style="display:inline-block;background:' . $brand . ';color:#ffffff;'
        . 'text-decoration:none;padding:12px 16px;border-radius:10px;'
        . 'font-weight:700;line-height:1.2;">'
        . 'View full details'
        . '</a>'
        . '</p>';

    // Optional: tiny secondary helper text under button (keeps tone warm)
    $helper =
        '<p style="margin:10px 0 0;color:' . $muted . ';font-size:13px;line-height:1.4;">'
        . 'If that button doesn’t work, copy and paste this link into your browser:<br>'
        . '<span style="word-break:break-all;color:' . $brand . ';">' . $safeUrl . '</span>'
        . '</p>';

    return
        pf_email_logo_html()
        . '<h2 style="margin:0 0 10px;font-size:18px;letter-spacing:-0.01em;color:' . $text . ';">'
        . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8')
        . '</h2>'
        . '<p style="margin:0 0 14px;color:' . $text . ';line-height:1.5;">'
        . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8')
        . '</p>'
        . $badge
        . $metaHtml
        . '<hr style="border:none;border-top:1px solid ' . $border . ';margin:18px 0;">'
        . '<h3 style="margin:0 0 8px;font-size:15px;color:' . $text . ';">Key things to know</h3>'
        . '<p style="margin:0 0 10px;color:' . $text . ';line-height:1.55;">' . $safeCapsule . '</p>'
        . $button
        . $helper
        . '<p style="margin:14px 0 0;color:' . $muted . ';font-size:13px;line-height:1.4;">'
        . 'Tip: don’t click links or share codes unless you’ve verified the sender via a trusted route.'
        . '</p>';
}


function pf_email_check_text(
    string $mode,
    string $shortVerdict,
    string $inputCapsule,
    string $viewUrl,
    ?string $metaLine = null
): string {
    $headline = ($mode === 'scamcheck') ? 'Plainfully ScamCheck result' : 'Plainfully Clarification result';

    $txt = $headline . "\n"
        . "Verdict: {$shortVerdict}\n";

    if ($metaLine !== null && trim($metaLine) !== '') {
        $txt .= $metaLine . "\n";
    }

    $txt .= "\nKey things to know:\n{$inputCapsule}\n\n"
        . "View full details:\n{$viewUrl}\n";

    return $txt;
}
