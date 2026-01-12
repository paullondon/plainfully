<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/views/results/confirm_email.php
 * Purpose:
 *   Guest confirmation step for result links.
 *   Asks the user to confirm the email address the result was
 *   sent to, before viewing the details.
 *
 * Change history:
 *   - 2025-12-28 16:44:40Z  Initial MVP page
 * ============================================================
 *
 * View model ($vm):
 *   - token: string
 *   - errors: string[]
 *   - oldEmail: string
 */

$token    = (string)($vm['token'] ?? '');
$errors   = is_array($vm['errors'] ?? null) ? $vm['errors'] : [];
$oldEmail = (string)($vm['oldEmail'] ?? '');
?>

<div class="pf-card" style="max-width:520px;margin:0 auto;">
  <h2 style="margin:0 0 10px 0;">Confirm your email address</h2>

  <div class="pf-info" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:10px;padding:12px 14px;margin:0 0 14px 0;">
    <p style="margin:0;">
      This result was sent to a specific email address.
      To keep things private, please confirm the same email address below before viewing the details.
    </p>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="pf-errors" style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px 14px;margin:0 0 14px 0;">
      <ul style="margin:0;padding-left:18px;">
        <?php foreach ($errors as $e): ?>
          <li><?php echo htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="/r/<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
    <label for="email" style="display:block;font-weight:600;margin:0 0 6px 0;">Email address</label>
    <input
      type="email"
      id="email"
      name="email"
      value="<?php echo htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8'); ?>"
      autocomplete="email"
      required
      style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px;"
      placeholder="you@example.com"
    >

    <button
      type="submit"
      style="margin-top:12px;width:100%;padding:10px 12px;border:0;border-radius:10px;background:#111827;color:#fff;font-weight:700;cursor:pointer;"
    >
      Continue
    </button>
  </form>

  <p style="margin:12px 0 0 0;color:#6b7280;font-size:13px;">
    If this wasn’t you, you can safely close this page.
  </p>
</div>
