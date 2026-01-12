<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/views/clarifications/view.php
 * Purpose:
 *   Renders a single clarification result (web + email/SMS views).
 *
 * Design goals:
 *   - Uses your existing global CSS tokens/classes (dark/light friendly)
 *   - Avoids hard-coded colours
 *   - Never throws notices/warnings if fields are missing
 *
 * Expected inputs:
 *   - $vm (array) from clarifications_view_controller()
 *       ['check'=>..., 'plan'=>..., 'key_points'=>..., 'risks'=>..., 'next_steps'=>..., 'short_verdict'=>...]
 * ============================================================
 */

$vm = (isset($vm) && is_array($vm)) ? $vm : [];

$check = (isset($vm['check']) && is_array($vm['check'])) ? $vm['check'] : [];
$plan  = (isset($vm['plan']) && is_array($vm['plan'])) ? $vm['plan'] : [];

$keyPoints = (isset($vm['key_points']) && is_array($vm['key_points'])) ? $vm['key_points'] : [];
$risks     = (isset($vm['risks']) && is_array($vm['risks'])) ? $vm['risks'] : [];
$nextSteps = (isset($vm['next_steps']) && is_array($vm['next_steps'])) ? $vm['next_steps'] : [];

$headline = (string)($vm['short_verdict'] ?? $check['short_summary'] ?? 'Your result is ready');

$createdAt = (string)($check['created_at'] ?? '');
$channel   = (string)($check['channel'] ?? '');
$id        = (int)($check['id'] ?? 0);

// -------------------------------
// Safe fallbacks (avoid empties)
// -------------------------------
if (empty($keyPoints)) {
    $keyPoints = ['No key points were provided for this result.'];
}
if (empty($risks)) {
    $risks = ['No major risks were identified, but stay cautious with links and any request for money or personal details.'];
}
if (empty($nextSteps)) {
    $nextSteps = ['Most people use this to decide whether to ignore the message, verify the sender via a trusted route, or ask for a second opinion.'];
}

$planName  = (string)($plan['name'] ?? 'Basic');
$planUsed  = $plan['used'] ?? null;
$planLimit = $plan['limit'] ?? null;

// -------------------------------
// MVP follow-up (single-use lock)
// NOTE: You asked for guided choices only (no free text).
// For MVP, lock is stored in browser localStorage per check-id.
// When you wire DB follow-ups later, swap this to server-enforced.
// -------------------------------
$followUpChoices = [
    'What should I do next, step-by-step?',
    'What are the biggest red flags (if any)?',
    'Can you rewrite a safe reply I could send back (if I respond)?',
    'How can I verify this is real using trusted contact routes?',
    'What should I avoid doing right now?',
];
$followUpChoices = array_slice($followUpChoices, 0, 5); // keep to 3–5 in UI by CSS; still safe here.
?>
<div class="pf-page">

  <!-- Page header card -->
  <div class="pf-card pf-card-lg" style="margin-bottom:16px;">
    <div class="pf-row" style="align-items:center; gap:12px;">
      <div style="flex:0 0 auto;">
        <img
          src="/assets/img/plainfully-logo-light.256.png"
          alt="Plainfully"
          style="width:42px;height:42px;border-radius:10px;display:block;"
          loading="lazy"
        >
      </div>

      <div style="flex:1 1 auto; min-width:0;">
        <h1 class="pf-h1" style="margin:0; font-size:22px; line-height:1.2;">
          <?= htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') ?>
        </h1>

        <div class="pf-muted" style="margin-top:6px;">
          <?php if ($createdAt !== ''): ?>
            <?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?>
          <?php endif; ?>
          <?php if ($channel !== ''): ?>
            <span aria-hidden="true"> • </span><?= htmlspecialchars($channel, ENT_QUOTES, 'UTF-8') ?>
          <?php endif; ?>
          <?php if ($id > 0): ?>
            <span aria-hidden="true"> • </span>#<?= (int)$id ?>
          <?php endif; ?>
        </div>
      </div>

      <div style="flex:0 0 auto; text-align:right;">
        <div class="pf-pill" style="display:inline-block;">
          <?= htmlspecialchars($planName, ENT_QUOTES, 'UTF-8') ?>
          <?php if ($planLimit !== null && $planUsed !== null): ?>
            <span class="pf-muted">· <?= (int)$planUsed ?>/<?= (int)$planLimit ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Key things to know -->
  <div class="pf-card" style="margin-bottom:16px;">
    <h2 class="pf-h2" style="margin-top:0;">Key things to know</h2>
    <ul class="pf-list">
      <?php foreach ($keyPoints as $p): ?>
        <li><?= htmlspecialchars((string)$p, ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Risks / cautions -->
  <div class="pf-card" style="margin-bottom:16px;">
    <h2 class="pf-h2" style="margin-top:0;">Risks / cautions</h2>
    <ul class="pf-list">
      <?php foreach ($risks as $r): ?>
        <li><?= htmlspecialchars((string)$r, ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Next steps -->
  <div class="pf-card" style="margin-bottom:16px;">
    <h2 class="pf-h2" style="margin-top:0;">What people typically do next</h2>
    <ul class="pf-list">
      <?php foreach ($nextSteps as $s): ?>
        <li><?= htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- One guided follow-up -->
  <div class="pf-card">
    <h2 class="pf-h2" style="margin-top:0;">One guided follow-up (optional)</h2>
    <p class="pf-muted" style="margin-top:-4px;">
      Pick one question. Once used, follow-ups are locked for this result.
    </p>

    <div id="pf-followup-wrap" class="pf-stack" style="gap:10px;">
      <?php foreach ($followUpChoices as $i => $label): ?>
        <button
          type="button"
          class="pf-btn pf-btn-secondary pf-followup-btn"
          data-followup="<?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?>"
          style="width:100%; text-align:left; padding:14px 14px; border-radius:14px;"
        >
          <?= htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') ?>
        </button>
      <?php endforeach; ?>
    </div>

    <div id="pf-followup-result" style="display:none; margin-top:14px;">
      <div class="pf-divider" style="margin:14px 0;"></div>
      <div class="pf-muted" style="margin-bottom:8px;">Your follow-up:</div>
      <div id="pf-followup-chosen" class="pf-card" style="margin:0; padding:14px;"></div>
      <p class="pf-muted" style="margin-top:10px; font-size:13px;">
        MVP note: this lock is stored in your browser for now. When you wire DB follow-ups, this will become account-wide.
      </p>
    </div>
  </div>

</div>

<script>
(function () {
  try {
    var checkId = <?= (int)$id ?>;
    if (!checkId) { return; }

    var key = "pf_followup_used_" + String(checkId);
    var used = localStorage.getItem(key);

    var wrap = document.getElementById("pf-followup-wrap");
    var res  = document.getElementById("pf-followup-result");
    var out  = document.getElementById("pf-followup-chosen");

    function lockButtons(chosenText) {
      if (!wrap) { return; }
      var btns = wrap.querySelectorAll(".pf-followup-btn");
      btns.forEach(function (b) {
        b.disabled = true;
        b.classList.add("is-disabled");
        b.style.opacity = "0.55";
      });

      if (res && out) {
        out.textContent = chosenText || "Follow-up completed.";
        res.style.display = "block";
      }
    }

    if (used) {
      lockButtons(used);
      return;
    }

    if (!wrap) { return; }

    wrap.addEventListener("click", function (e) {
      var t = e.target;
      if (!t || !t.classList || !t.classList.contains("pf-followup-btn")) { return; }

      var chosen = t.getAttribute("data-followup") || "";
      if (!chosen) { return; }

      // Store lock BEFORE UI changes (fail-safe)
      localStorage.setItem(key, chosen);

      // Replace with chosen summary under the card
      lockButtons(chosen);

      // TODO (next step): submit follow-up to server + store in DB + run AI.
      // For now we only store the chosen question text.
    });

  } catch (err) {
    // fail-open: no follow-up lock if browser blocks storage
  }
})();
</script>
