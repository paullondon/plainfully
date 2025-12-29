<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/views/results/link_error.php
 * Purpose:
 *   Friendly error page for result-link failures (Flow B).
 *
 * Expects:
 *   $data = [
 *     'title'    => string,
 *     'message'  => string,
 *     'loginUrl' => string,
 *   ]
 *
 * Change history:
 *   - 2025-12-29  Initial version.
 * ============================================================
 */

$title    = htmlspecialchars((string)($data['title'] ?? 'Oops! Something went wrong.'), ENT_QUOTES, 'UTF-8');
$message  = htmlspecialchars((string)($data['message'] ?? 'Please try again.'), ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars((string)($data['loginUrl'] ?? '/login'), ENT_QUOTES, 'UTF-8');
?>

<div class="pf-card" style="max-width:560px;margin:0 auto;">
  <h1 style="margin:0 0 10px 0; font-size:22px; line-height:1.2;"><?php echo $title; ?></h1>

  <div class="pf-alert pf-alert-warning" style="margin:12px 0 16px 0;">
    <p style="margin:0;"><?php echo $message; ?></p>
  </div>

  <p style="margin:0 0 16px 0; color: var(--pf-text-muted, #6b7280);">
    You can sign in and view this from your dashboard.
  </p>

  <a class="pf-btn pf-btn-primary" href="<?php echo $loginUrl; ?>">
    Return to login
  </a>
</div>
