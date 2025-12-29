<?php declare(strict_types=1);

/**
 * Email templates for Plainfully (HTML + plain text builders).
 * Keep logic here so controllers stay clean.
 */

function pf_email_logo_html(): string
{
    $logoUrl = 'https://plainfully.com/assets/img/logo-icon.png';

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
    $safeVerdict = htmlspecialchars($shortVerdict, ENT_QUOTES, 'UTF-8');
    $safeCapsule = nl2br(htmlspecialchars($inputCapsule, ENT_QUOTES, 'UTF-8'));
    $safeUrl     = htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8');

    $headline = ($mode === 'scamcheck')
        ? 'ScamCheck result'
        : 'Clarification result';

    $intro = ($mode === 'scamcheck')
        ? 'We checked the message you forwarded.'
        : 'Here’s the simplest explanation of what your message means.';

    $metaHtml = '';
    if ($metaLine !== null && trim($metaLine) !== '') {
        $metaHtml = '<p style="margin:10px 0 0;color:#6b7280;font-size:13px;">'
            . htmlspecialchars($metaLine, ENT_QUOTES, 'UTF-8')
            . '</p>';
    }

    // CTA button
    $button = '<p style="margin:18px 0 0;">'
        . '<a href="' . $safeUrl . '" style="display:inline-block;background:#111827;color:#ffffff;'
        . 'text-decoration:none;padding:12px 16px;border-radius:10px;font-weight:700;">'
        . 'View full details'
        . '</a></p>';

    return
        pf_email_logo_html()
        . '<h2 style="margin:0 0 10px;font-size:18px;letter-spacing:-0.01em;">'
        . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8')
        . '</h2>'
        . '<p style="margin:0 0 14px;">' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
        . pf_email_badge_html($safeVerdict)
        . $metaHtml
        . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:18px 0;">'
        . '<h3 style="margin:0 0 8px;font-size:15px;">Key things to know</h3>'
        . '<p style="margin:0 0 10px;color:#111827;">' . $safeCapsule . '</p>'
        . $button
        . '<p style="margin:14px 0 0;color:#6b7280;font-size:13px;">'
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
