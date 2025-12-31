<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/support/email_templates.php
 * Purpose:
 *   Centralised email HTML/text builders for Plainfully.
 *
 * What this version changes (implements your steps 4–5):
 *   - Email wrapper is LIGHT by default (no more forced dark emails)
 *   - Adds dark-mode pairing using prefers-color-scheme (iOS Mail etc.)
 *   - Inner templates stop hard-coding dark backgrounds and instead
 *     inherit from the wrapper (keeps things consistent + maintainable)
 *
 * Notes:
 *   - Email clients vary: we keep LIGHT inline styles as a safe baseline.
 *   - Dark mode is an enhancement via <style>@media</style>.
 *   - No external CSS files are relied upon for emails (email clients often block them).
 *
 * Change history:
 *   - 2025-12-31  Email wrapper light-by-default + dark-mode pairing + inner cleanup
 * ============================================================
 */

/**
 * Small, reliable logo block for emails (PNG for client compatibility).
 */
function pf_email_logo_html(): string
{
    $logoUrl = 'https://plainfully.com/assets/img/plainfully-logo-light.256.png';

    return
        '<div class="pf-logo" style="margin:0 0 16px 0;text-align:left;">'
        . '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'alt="Plainfully" width="48" height="48" '
        . 'style="display:block;border:0;outline:none;text-decoration:none;">'
        . '</div>';
}

/**
 * Badge (light baseline). Dark-mode handled by wrapper CSS.
 */
function pf_email_badge_html(string $label): string
{
    $safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    // Light-safe inline baseline
    return
        '<div class="pf-badge" style="display:inline-block;padding:8px 12px;border-radius:999px;'
        . 'background:#EAF5F2;border:1px solid rgba(44,111,99,0.28);'
        . 'color:#2C6F63;font-weight:700;font-size:13px;line-height:1;">'
        . $safe
        . '</div>';
}

/**
 * Build “check result” email body (inner HTML only).
 * Keep it predictable; wrapper controls overall theme.
 */
function pf_email_check_inner_html(
    string $mode,              // 'scamcheck' | 'clarify' | 'generic'
    string $shortVerdict,
    string $inputCapsule,
    string $viewUrl,
    ?string $metaLine = null    // e.g. "Plan: Bronze · 2/3 used"
): string {
    // ---- Safety: escape everything that came from outside ----
    $safeVerdict = htmlspecialchars($shortVerdict, ENT_QUOTES, 'UTF-8');
    $safeCapsule = nl2br(htmlspecialchars($inputCapsule, ENT_QUOTES, 'UTF-8'));
    $safeUrl     = htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8');

    $headline = ($mode === 'scamcheck') ? 'ScamCheck result' : 'Clarification result';

    $intro = ($mode === 'scamcheck')
        ? 'We’ve checked the message you forwarded.'
        : 'Here’s the simplest explanation of what your message means.';

    $metaHtml = '';
    if ($metaLine !== null && trim($metaLine) !== '') {
        $metaHtml =
            '<p class="pf-muted" style="margin:10px 0 0;color:#6B7280;font-size:13px;line-height:1.4;">'
            . htmlspecialchars($metaLine, ENT_QUOTES, 'UTF-8')
            . '</p>';
    }

    // CTA button (light baseline). Wrapper adds dark-mode improvements.
    $button =
        '<p style="margin:18px 0 0;">'
        . '<a class="pf-btn" href="' . $safeUrl . '" '
        . 'style="display:inline-block;background:#2C6F63;color:#ffffff;'
        . 'text-decoration:none;padding:12px 16px;border-radius:10px;'
        . 'font-weight:700;line-height:1.2;">'
        . 'View full details'
        . '</a>'
        . '</p>';

    // Helper (keeps anxiety low if button fails)
    $helper =
        '<p class="pf-muted" style="margin:10px 0 0;color:#6B7280;font-size:13px;line-height:1.4;">'
        . 'If that button doesn’t work, copy and paste this link into your browser:<br>'
        . '<span style="word-break:break-all;color:#2C6F63;">' . $safeUrl . '</span>'
        . '</p>';

    return
        pf_email_logo_html()
        . '<h2 class="pf-h2" style="margin:0 0 10px;font-size:18px;letter-spacing:-0.01em;color:#111827;">'
        . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8')
        . '</h2>'
        . '<p class="pf-p" style="margin:0 0 14px;color:#111827;line-height:1.5;">'
        . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8')
        . '</p>'
        . pf_email_badge_html($safeVerdict)
        . $metaHtml
        . '<hr class="pf-hr" style="border:none;border-top:1px solid #E5E7EB;margin:18px 0;">'
        . '<h3 class="pf-h3" style="margin:0 0 8px;font-size:15px;color:#111827;">Key things to know</h3>'
        . '<p class="pf-p" style="margin:0 0 10px;color:#111827;line-height:1.55;">' . $safeCapsule . '</p>'
        . $button
        . $helper
        . '<p class="pf-muted" style="margin:14px 0 0;color:#6B7280;font-size:13px;line-height:1.4;">'
        . 'Tip: don’t click links or share codes unless you’ve verified the sender via a trusted route.'
        . '</p>';
}

/**
 * Plain text builder (unchanged conceptually).
 */
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

/**
 * Wrapper template (FULL HTML email).
 *
 * LIGHT by default + dark-mode pairing via prefers-color-scheme.
 */
function pf_email_template(string $subject, string $innerHtml): string
{
    $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');

    // PNG for email reliability
    $logoUrl = 'https://plainfully.com/assets/img/plainfully-logo-light.256.png';

    // LIGHT baseline (inline). Dark mode overrides via <style> for clients that support it (iOS Mail etc.).
    return '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- Tell clients we support both schemes -->
  <meta name="color-scheme" content="light dark">
  <meta name="supported-color-schemes" content="light dark">

  <title>' . $safeSubject . '</title>

  <style>
    /* ---- Baseline token set ---- */
    :root{
      --pf-bg:#F7F9FA;
      --pf-surface:#FFFFFF;
      --pf-border:#E5E7EB;

      --pf-text:#111827;
      --pf-muted:#6B7280;

      --pf-brand:#2C6F63;
      --pf-brand-soft:#5ABFA8;
      --pf-accent:#CE9F77;
    }

    /*
      Apple Mail / iOS:
      Prevents "smart" auto darkening that can wreck contrast.
      Supported widely enough to be worth it; ignored elsewhere.
    */
    body, .pf-body, .pf-card { -webkit-text-size-adjust:100%; }

    /* ---- Dark-mode pairing (supported by Apple Mail / iOS, some others) ---- */
    @media (prefers-color-scheme: dark){
      :root{
        --pf-bg:#0B0F14;
        --pf-surface:#111827;
        --pf-border:#1F2937;

        --pf-text:#E5E7EB;
        --pf-muted:#9CA3AF;

        /* brand shifts slightly brighter for contrast */
        --pf-brand:#5ABFA8;
      }

      /* Force the key surfaces/text so iOS Mail doesn’t “guess” */
      body, .pf-body{
        background:var(--pf-bg) !important;
        color:var(--pf-text) !important;
      }

      .pf-card{
        background:var(--pf-surface) !important;
        border-color:var(--pf-border) !important;
        color:var(--pf-text) !important;
      }

      .pf-title{ color:#FFFFFF !important; }
      .pf-tagline{ color:var(--pf-muted) !important; }
      .pf-muted{ color:var(--pf-muted) !important; }
    }
  </style>
</head>

<body class="pf-body" style="
  margin:0;
  padding:0;
  background:#F7F9FA;
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
  color:#111827;
">
  <div style="max-width:640px;margin:0 auto;padding:28px 20px;">

    <!-- Header -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
      <img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '"
           width="36" height="36"
           alt="Plainfully"
           style="display:block;border:0;outline:none;text-decoration:none;">
      <div>
        <div class="pf-title" style="font-weight:700;font-size:16px;line-height:1;color:#111827;">
          Plainfully
        </div>
        <div class="pf-tagline" style="font-size:13px;color:#6B7280;">
          Clear answers. Fewer worries.
        </div>
      </div>
    </div>

    <!-- Card -->
    <div class="pf-card" style="
      background:#FFFFFF;
      border:1px solid #E5E7EB;
      border-radius:16px;
      padding:22px;
      color:#111827;
    ">
      ' . $innerHtml . '
    </div>

    <!-- Footer -->
    <div class="pf-muted" style="color:#6B7280;font-size:12px;margin-top:16px;line-height:1.5;">
      You’re receiving this because you used Plainfully via email.<br>
      Operated by Hissing Goat Studios.
    </div>

  </div>
</body>
</html>';
}

