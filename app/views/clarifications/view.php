<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/views/clarifications/view.php
 * Purpose:
 *   Render a single clarification result.
 *
 * Why this version exists:
 *   - Prevents undefined-index warnings when older controllers / flows
 *     provide a different view-model shape (e.g. /r/{token} redirects).
 *   - Fail-closed for output safety: everything is HTML-escaped.
 *
 * Expected inputs:
 *   - $vm (array) from controller, containing:
 *       - plan (array)
 *       - key_points (array<string>)
 *       - risks (array<string>)
 *       - next_steps (array<string>)
 *       - short_verdict (string)
 *       - check (array) (optional)
 *
 * Safe behaviour:
 *   - If any fields are missing, we show sensible defaults with NO PHP warnings.
 * ============================================================
 */

/** @var array|null $vm */
$vm = (isset($vm) && is_array($vm)) ? $vm : [];

/**
 * Escape helper (never pass null to htmlspecialchars).
 */
function h(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// ------------------------------
// View-model safe defaults
// ------------------------------
$plan = (isset($vm['plan']) && is_array($vm['plan'])) ? $vm['plan'] : [];
$keyPoints = $vm['key_points'] ?? [];
$risks = $vm['risks'] ?? [];
$nextSteps = $vm['next_steps'] ?? [];
$shortVerdict = (string)($vm['short_verdict'] ?? 'Your result is ready');

if (!is_array($keyPoints)) { $keyPoints = []; }
if (!is_array($risks)) { $risks = []; }
if (!is_array($nextSteps)) { $nextSteps = []; }

// Ensure non-empty, user-friendly defaults
if (count($keyPoints) === 0) {
    $keyPoints = ['We processed your message and generated a summary.'];
}
if (count($risks) === 0) {
    $risks = ['No major risks were identified, but stay cautious with links and any requests for money or personal details.'];
}
if (count($nextSteps) === 0) {
    $nextSteps = ['Ignore it if it looks irrelevant, or contact the organisation via a trusted route if you’re unsure.'];
}

// Plan labels (optional, shown lightly)
$planName = (string)($plan['name'] ?? '');
$planUsed = $plan['used'] ?? null;
$planLimit = $plan['limit'] ?? null;

// ------------------------------
// Render
// ------------------------------
?>
<div class="pf-card" style="max-width:900px;margin:20px auto;">
  <h1 style="margin:0 0 8px 0;">Clarification result</h1>

  <?php if ($planName !== ''): ?>
    <div style="margin:0 0 14px 0;color:var(--pf-text-muted);font-size:13px;">
      <strong><?= h($planName) ?></strong>
      <?php if ($planUsed !== null && $planLimit !== null): ?>
        — <?= h($planUsed) ?> / <?= h($planLimit) ?> used
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div style="padding:14px;border:1px solid var(--pf-border);border-radius:14px;margin:0 0 16px 0;background:var(--pf-surface);">
    <div style="font-weight:700;margin:0 0 6px 0;">Verdict</div>
    <div style="font-size:16px;line-height:1.35;"><?= h($shortVerdict) ?></div>
  </div>

  <h2 style="margin:0 0 10px 0;font-size:18px;">Key things to know</h2>
  <ul style="margin:0 0 18px 18px;">
    <?php foreach ($keyPoints as $p): ?>
      <li style="margin:0 0 8px 0;"><?= h($p) ?></li>
    <?php endforeach; ?>
  </ul>

  <h2 style="margin:0 0 10px 0;font-size:18px;">Risks / cautions</h2>
  <ul style="margin:0 0 18px 18px;">
    <?php foreach ($risks as $r): ?>
      <li style="margin:0 0 8px 0;"><?= h($r) ?></li>
    <?php endforeach; ?>
  </ul>

  <h2 style="margin:0 0 10px 0;font-size:18px;">What people typically do with this information</h2>
  <ul style="margin:0 0 6px 18px;">
    <?php foreach ($nextSteps as $s): ?>
      <li style="margin:0 0 8px 0;"><?= h($s) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
