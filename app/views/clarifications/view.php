<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/views/clarifications/view.php
 * Purpose:
 *   Render a single clarification result page (web view).
 *
 * Key UX rules (MVP):
 *   - Calm, readable summary first.
 *   - Show optional detail sections only when present.
 *   - Follow-ups are NOT free text: user picks 1 guided question (max 1 total).
 *   - After a follow-up is chosen once, no further follow-ups are allowed.
 *
 * Implementation notes:
 *   - This view is defensive: no notices/warnings if fields are missing.
 *   - Follow-up enforcement is client-side (localStorage) until DB wiring exists.
 *   - Follow-up action posts to /clarifications/new with a prebuilt prompt.
 * ============================================================
 */

/** @var array $vm */
$vm = isset($vm) && is_array($vm) ? $vm : [];

function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function arr($v): array {
    return is_array($v) ? $v : [];
}

/** Core record */
$check = arr($vm['check'] ?? []);
$checkId = (int)($check['id'] ?? 0);

/** AI-derived view pieces (already parsed in controller) */
$plan = arr($vm['plan'] ?? []);
$keyPoints = arr($vm['key_points'] ?? []);
$risks = arr($vm['risks'] ?? []);
$nextSteps = arr($vm['next_steps'] ?? []);
$shortVerdict = (string)($vm['short_verdict'] ?? ($check['short_summary'] ?? 'Result ready'));

/** Optional “web” fields from CheckResult / AI JSON (safe fallbacks) */
$whatSays = (string)($vm['web_what_the_message_says'] ?? '');
$whatAsks = (string)($vm['web_what_its_asking_for'] ?? '');
$scamLine = (string)($vm['web_scam_level_line'] ?? '');
$lowRiskNote = (string)($vm['web_low_risk_note'] ?? '');
$scamExpl = (string)($vm['web_scam_explanation'] ?? '');

/**
 * Follow-up questions:
 * Expected shape (preferred, once you wire it):
 *   $vm['followups'] = [
 *      ['id' => 'f1', 'label' => 'Question text...'],
 *      ...
 *   ];
 *
 * Backward-compatible fallback:
 *   If not present, we show a fixed set (still AI-friendly wording).
 */
$followups = arr($vm['followups'] ?? []);
if (count($followups) < 3) {
    $followups = [
        ['id' => 'f1', 'label' => 'What should I do next, step-by-step?'],
        ['id' => 'f2', 'label' => 'What parts of this message are the biggest red flags (if any)?'],
        ['id' => 'f3', 'label' => 'Can you rewrite a safe reply I could send back (if I choose to respond)?'],
        ['id' => 'f4', 'label' => 'How can I verify this is real using trusted contact routes?'],
    ];
}

/** Cap to 3–5 options */
$followups = array_slice($followups, 0, 5);

/** Plan badge label (Basic/Unlimited/Admin) — prefer controller-provided if available */
$planName = (string)($plan['name'] ?? '');
if ($planName === '') {
    // safe fallback: infer from user table if you later pass it; else Basic
    $planName = 'Basic';
}
?>
<style>
/* ============================================================
   Page-local styling (uses your existing CSS tokens)
   ============================================================ */
.pf-result-wrap{max-width:980px;margin:18px auto;padding:0 14px;}
.pf-result-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 14px;}
.pf-logo{display:flex;align-items:center;gap:10px;text-decoration:none;}
.pf-logo img{width:34px;height:34px;border-radius:10px;display:block}
.pf-logo span{font-weight:700;color:var(--pf-text);letter-spacing:.2px}
.pf-chip{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;border:1px solid var(--pf-border);background:var(--pf-surface);color:var(--pf-text);font-size:13px;white-space:nowrap;}
.pf-chip strong{font-weight:700}
.pf-cardx{border:1px solid var(--pf-border);background:var(--pf-surface);border-radius:16px;padding:16px;margin:0 0 14px;}
.pf-title{margin:0 0 8px 0;font-size:22px;line-height:1.2;}
.pf-muted{color:var(--pf-text-muted);}
.pf-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;}
.pf-col{flex:1;min-width:240px;}
.pf-h2{margin:0 0 10px 0;font-size:16px;}
.pf-ul{margin:0;padding-left:18px;}
.pf-ul li{margin:6px 0;}
.pf-banner{padding:12px 14px;border-radius:14px;border:1px solid var(--pf-border);background:rgba(255,255,255,0.02);}
.pf-banner strong{font-weight:800;}
.pf-followup-grid{display:flex;gap:10px;flex-wrap:wrap;}
.pf-followup-btn{border:1px solid var(--pf-border);background:var(--pf-surface);color:var(--pf-text);padding:10px 12px;border-radius:12px;cursor:pointer;min-width:240px;flex:1;}
.pf-followup-btn:hover{filter:brightness(1.06);}
.pf-followup-btn[disabled]{opacity:.45;cursor:not-allowed;filter:none;}
.pf-divider{height:1px;background:var(--pf-border);margin:14px 0;}
.pf-note{font-size:13px;color:var(--pf-text-muted);margin:8px 0 0;}
</style>

<div class="pf-result-wrap">
  <div class="pf-result-head">
    <a class="pf-logo" href="/dashboard" aria-label="Back to dashboard">
      <img src="/assets/img/plainfully-logo-light.256.png" alt="Plainfully" loading="lazy">
      <span>Plainfully</span>
    </a>
    <div class="pf-chip" title="Your current plan">
      <strong><?= h($planName) ?></strong>
    </div>
  </div>

  <div class="pf-cardx">
    <h1 class="pf-title"><?= h($shortVerdict) ?></h1>
    <div class="pf-muted" style="font-size:13px;">
      <?= h((string)($check['created_at'] ?? '')) ?>
      <?php if (!empty($check['channel'])): ?>
        • <?= h((string)$check['channel']) ?>
      <?php endif; ?>
      <?php if ($checkId > 0): ?>
        • #<?= h((string)$checkId) ?>
      <?php endif; ?>
    </div>

    <?php if ($scamLine !== ''): ?>
      <div class="pf-banner" style="margin-top:12px;">
        <strong><?= h($scamLine) ?></strong>
        <?php if ($lowRiskNote !== ''): ?>
          <div class="pf-note"><?= h($lowRiskNote) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($whatSays !== '' || $whatAsks !== ''): ?>
      <div class="pf-row">
        <?php if ($whatSays !== ''): ?>
          <div class="pf-col">
            <div class="pf-h2">What the message says</div>
            <div class="pf-banner"><?= nl2br(h($whatSays)) ?></div>
          </div>
        <?php endif; ?>
        <?php if ($whatAsks !== ''): ?>
          <div class="pf-col">
            <div class="pf-h2">What it’s asking for</div>
            <div class="pf-banner"><?= nl2br(h($whatAsks)) ?></div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($scamExpl !== ''): ?>
      <div class="pf-divider"></div>
      <div class="pf-h2">Why this verdict</div>
      <div class="pf-banner"><?= nl2br(h($scamExpl)) ?></div>
    <?php endif; ?>
  </div>

  <div class="pf-cardx">
    <div class="pf-h2">Key things to know</div>
    <?php if (count($keyPoints) > 0): ?>
      <ul class="pf-ul">
        <?php foreach ($keyPoints as $p): ?>
          <?php if (is_string($p) && trim($p) !== ''): ?>
            <li><?= h($p) ?></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="pf-muted">No key points were provided for this result.</div>
    <?php endif; ?>
  </div>

  <div class="pf-cardx">
    <div class="pf-h2">Risks / cautions</div>
    <?php if (count($risks) > 0): ?>
      <ul class="pf-ul">
        <?php foreach ($risks as $r): ?>
          <?php if (is_string($r) && trim($r) !== ''): ?>
            <li><?= h($r) ?></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="pf-muted">No major risks were identified, but stay cautious with links and any request for money or personal details.</div>
    <?php endif; ?>
  </div>

  <div class="pf-cardx">
    <div class="pf-h2">What people typically do next</div>
    <?php if (count($nextSteps) > 0): ?>
      <ul class="pf-ul">
        <?php foreach ($nextSteps as $s): ?>
          <?php if (is_string($s) && trim($s) !== ''): ?>
            <li><?= h($s) ?></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="pf-muted">Most people use this to decide whether to ignore the message, verify the sender via a trusted route, or ask for a second opinion.</div>
    <?php endif; ?>
  </div>

  <div class="pf-cardx" id="pf-followup-card">
    <div class="pf-h2">One guided follow‑up (optional)</div>
    <div class="pf-muted" style="margin:0 0 10px 0;">
      Pick one question. Once used, follow‑ups are locked for this result.
    </div>

    <div class="pf-followup-grid" id="pf-followup-options">
      <?php foreach ($followups as $f):
        $fid = (string)($f['id'] ?? '');
        $label = (string)($f['label'] ?? '');
        if ($fid === '' || trim($label) === '') { continue; }

        // We post to /clarifications/new and ask the controller to prefill.
        // (Server wiring can be added later; this already works as a normal new check.)
        $prefill =
          "Follow-up to clarification #{$checkId}\n\n" .
          "Question: {$label}\n\n" .
          "Context: (use the existing result details above — do NOT ask for the original email again unless essential)\n";
      ?>
        <form method="post" action="/clarifications/new" style="margin:0;">
          <input type="hidden" name="tone" value="calm">
          <input type="hidden" name="text" value="<?= h($prefill) ?>">
          <button class="pf-followup-btn" type="submit"
                  data-followup-btn="1"
                  data-followup-qid="<?= h($fid) ?>"
                  data-followup-label="<?= h($label) ?>">
            <?= h($label) ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>

    <div class="pf-divider"></div>

    <div id="pf-followup-chosen" style="display:none;">
      <div class="pf-h2" style="margin:0 0 8px 0;">Your follow‑up</div>
      <div class="pf-banner" id="pf-followup-chosen-text"></div>
      <div class="pf-note">Follow‑ups are now locked for this result.</div>
    </div>

    <div class="pf-note">
      MVP note: this lock is stored in your browser for now. When you wire DB follow-ups, this will become account‑wide.
    </div>
  </div>
</div>

<script>
/**
 * ============================================================
 * Follow-up lock (client-side MVP)
 * ============================================================
 * - Once a follow-up is selected for a check_id, we disable the buttons
 * - We show the chosen follow-up underneath
 *
 * Storage key:
 *   pf_followup_used_{checkId} = JSON string { id, label, ts }
 */
(function () {
  var checkId = <?= (int)$checkId ?>;
  if (!checkId) return;

  var key = "pf_followup_used_" + checkId;
  var used = null;

  try {
    var raw = window.localStorage.getItem(key);
    if (raw) used = JSON.parse(raw);
  } catch (e) {}

  var btns = document.querySelectorAll("[data-followup-btn='1']");
  var chosenWrap = document.getElementById("pf-followup-chosen");
  var chosenText = document.getElementById("pf-followup-chosen-text");

  function lockUI(selected) {
    btns.forEach(function (b) { b.disabled = true; });
    if (selected && selected.label && chosenWrap && chosenText) {
      chosenText.textContent = selected.label;
      chosenWrap.style.display = "block";
    }
  }

  if (used && used.label) {
    lockUI(used);
    return;
  }

  // On click, store lock before navigating
  btns.forEach(function (b) {
    b.addEventListener("click", function () {
      try {
        var payload = {
          id: b.getAttribute("data-followup-qid") || "",
          label: b.getAttribute("data-followup-label") || "",
          ts: Date.now()
        };
        window.localStorage.setItem(key, JSON.stringify(payload));
        lockUI(payload);
      } catch (e) {}
    }, { passive: true });
  });
})();
</script>
