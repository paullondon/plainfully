<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/views/clarifications/view.php
 * Purpose:
 *   Renders a single clarification result in a calm, readable layout.
 *
 * Key goals:
 *   - Never throw notices/warnings if fields are missing (robust defaults)
 *   - Present the result as "Verdict + Key points + Risks + Next steps"
 *   - Provide a single "guided follow-up" action (MVP) via the existing
 *     /clarifications/new endpoint (web channel).
 *
 * Follow-up behaviour (MVP):
 *   - We don't add any new DB columns here.
 *   - We enforce "one follow-up" client-side (localStorage) to match the
 *     product rule, without breaking the pipeline if backend work isn't
 *     ready yet.
 * ============================================================
 */

/** @var array $vm */
$vm = (isset($vm) && is_array($vm)) ? $vm : [];

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function arr($v): array { return is_array($v) ? $v : []; }
function str_or($v, string $fallback=''): string { $s = trim((string)($v ?? '')); return $s !== '' ? $s : $fallback; }

/**
 * Normalise view model (fail-safe).
 */
$check = arr($vm['check'] ?? []);
$plan  = arr($vm['plan'] ?? []);
$keyPoints = $vm['key_points'] ?? [];
$risks     = $vm['risks'] ?? [];
$nextSteps = $vm['next_steps'] ?? [];
$shortVerdict = $vm['short_verdict'] ?? ($check['short_summary'] ?? '');

if (!is_array($keyPoints)) { $keyPoints = []; }
if (!is_array($risks))     { $risks = []; }
if (!is_array($nextSteps)) { $nextSteps = []; }

$checkId = (int)($check['id'] ?? 0);
$created = str_or($check['created_at'] ?? '', '');
$channel = str_or($check['channel'] ?? '', '');
$isPaid  = (int)($check['is_paid'] ?? 0) === 1;

$planName = str_or($plan['name'] ?? '', ($isPaid ? 'Unlimited' : 'Basic'));
$planUsed = $plan['used'] ?? null;
$planLimit = $plan['limit'] ?? null;

$headline = str_or($shortVerdict, 'Clarification result');
$subline  = $created !== '' ? ('Created: ' . $created) : '';

/** Newer CheckResult pieces (optional) */
$whatSays = str_or($vm['web_what_the_message_says'] ?? '', '');
$whatAsks = str_or($vm['web_what_its_asking_for'] ?? '', '');
$scamLine = str_or($vm['web_scam_level_line'] ?? '', '');
$lowNote  = str_or($vm['web_low_risk_note'] ?? '', '');
$scamWhy  = str_or($vm['web_scam_explanation'] ?? '', '');

/** If controller passed $ai separately, try that too */
$ai = isset($ai) && is_array($ai) ? $ai : [];
if ($whatSays === '') { $whatSays = str_or($ai['web_what_the_message_says'] ?? '', ''); }
if ($whatAsks === '') { $whatAsks = str_or($ai['web_what_its_asking_for'] ?? '', ''); }
if ($scamLine === '') { $scamLine = str_or($ai['web_scam_level_line'] ?? '', ''); }
if ($lowNote  === '') { $lowNote  = str_or($ai['web_low_risk_note'] ?? '', ''); }
if ($scamWhy  === '') { $scamWhy  = str_or($ai['web_scam_explanation'] ?? '', ''); }

if (count($keyPoints) === 0) {
    $keyPoints = [$headline];
}

if (count($risks) === 0) {
    $risks = [
        'No major risks were identified, but you should still be cautious with links and any requests for money or personal details.'
    ];
}

if (count($nextSteps) === 0) {
    $nextSteps = [
        'If you’re unsure, contact the organisation using a trusted route (official website/number).',
        'Don’t click links or share codes until you’ve verified who it’s from.',
        'If money is involved, pause and double-check before doing anything.'
    ];
}
?>
<div class="pf-card" style="max-width:980px;margin:20px auto;">
  <div style="display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;">
    <div style="min-width:260px;">
      <h1 style="margin:0 0 6px 0;line-height:1.2;"><?= h($headline) ?></h1>
      <div style="color:var(--pf-text-muted);font-size:13px;">
        <?= h($subline) ?><?= $channel !== '' ? (' · Channel: ' . h($channel)) : '' ?><?= $checkId > 0 ? (' · ID: ' . h((string)$checkId)) : '' ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <span class="pf-badge" style="padding:6px 10px;border-radius:999px;border:1px solid var(--pf-border);background:var(--pf-surface);">
        <?= h($planName) ?>
        <?php if (is_int($planUsed) || is_numeric($planUsed)): ?>
          <?php if (is_int($planLimit) || is_numeric($planLimit)): ?>
            <span style="color:var(--pf-text-muted);margin-left:6px;"><?= h((string)$planUsed) ?>/<?= h((string)$planLimit) ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </span>
      <a class="pf-btn pf-btn-secondary" href="/dashboard">Back to dashboard</a>
    </div>
  </div>

  <?php if ($scamLine !== '' || $lowNote !== ''): ?>
    <div style="margin-top:14px;padding:12px;border:1px solid var(--pf-border);border-radius:14px;background:var(--pf-surface);">
      <?php if ($scamLine !== ''): ?>
        <div style="font-weight:700;"><?= h($scamLine) ?></div>
      <?php endif; ?>
      <?php if ($lowNote !== ''): ?>
        <div style="color:var(--pf-text-muted);margin-top:6px;"><?= h($lowNote) ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr;gap:12px;margin-top:14px;">

    <?php if ($whatSays !== '' || $whatAsks !== ''): ?>
      <div style="padding:14px;border:1px solid var(--pf-border);border-radius:14px;background:var(--pf-surface);">
        <div style="font-weight:700;margin-bottom:8px;">What this message is saying</div>
        <div style="white-space:pre-wrap;word-break:break-word;"><?= h($whatSays !== '' ? $whatSays : '—') ?></div>
        <div style="height:10px;"></div>
        <div style="font-weight:700;margin-bottom:8px;">What it’s asking for</div>
        <div style="white-space:pre-wrap;word-break:break-word;"><?= h($whatAsks !== '' ? $whatAsks : '—') ?></div>
      </div>
    <?php endif; ?>

    <?php if ($scamWhy !== ''): ?>
      <div style="padding:14px;border:1px solid var(--pf-border);border-radius:14px;background:var(--pf-surface);">
        <div style="font-weight:700;margin-bottom:8px;">Why this risk level was given</div>
        <div style="white-space:pre-wrap;word-break:break-word;"><?= h($scamWhy) ?></div>
      </div>
    <?php endif; ?>

    <div style="padding:14px;border:1px solid var(--pf-border);border-radius:14px;background:var(--pf-surface);">
      <div style="font-weight:700;margin-bottom:8px;">Key things to know</div>
      <ul style="margin:0;padding-left:18px;display:grid;gap:6px;">
        <?php foreach ($keyPoints as $p): ?>
          <?php $p = trim((string)$p); if ($p === '') { continue; } ?>
          <li><?= h($p) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div style="padding:14px;border:1px solid var(--pf-border);border-radius:14px;background:var(--pf-surface);">
      <div style="font-weight:700;margin-bottom:8px;">Risks / cautions</div>
      <ul style="margin:0;padding-left:18px;display:grid;gap:6px;">
        <?php foreach ($risks as $r): ?>
          <?php $r = trim((string)$r); if ($r === '') { continue; } ?>
          <li><?= h($r) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div style="padding:14px;border:1px solid var(--pf-border);border-radius:14px;background:var(--pf-surface);">
      <div style="font-weight:700;margin-bottom:8px;">What people typically do with this information</div>
      <ul style="margin:0;padding-left:18px;display:grid;gap:6px;">
        <?php foreach ($nextSteps as $n): ?>
          <?php $n = trim((string)$n); if ($n === '') { continue; } ?>
          <li><?= h($n) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div style="padding:14px;border:1px solid var(--pf-border);border-radius:14px;background:var(--pf-surface);">
      <div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
        <div>
          <div style="font-weight:800;">One guided follow‑up</div>
          <div style="color:var(--pf-text-muted);font-size:13px;margin-top:4px;">
            Ask one extra question about this result. (MVP: one follow‑up per clarification.)
          </div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button class="pf-btn pf-btn-primary" type="button" id="pfFollowUpBtn">Ask a follow‑up</button>
          <a class="pf-btn pf-btn-secondary" href="/clarifications/new">New clarification</a>
        </div>
      </div>

      <form id="pfFollowUpForm" method="post" action="/clarifications/new" style="display:none;margin-top:12px;">
        <input type="hidden" name="tone" value="calm">
        <textarea name="text" id="pfFollowUpText" style="width:100%;min-height:140px;padding:10px;border-radius:12px;border:1px solid var(--pf-border);background:var(--pf-surface);color:var(--pf-text);"></textarea>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:10px;">
          <button class="pf-btn pf-btn-secondary" type="button" id="pfFollowUpCancel">Cancel</button>
          <button class="pf-btn pf-btn-primary" type="submit" id="pfFollowUpSubmit">Send follow‑up</button>
        </div>
      </form>

      <div id="pfFollowUpDone" style="display:none;margin-top:10px;color:var(--pf-text-muted);font-size:13px;">
        Follow‑up already used for this clarification.
      </div>
    </div>

  </div>
</div>

<script>
(function () {
  var checkId = <?= (int)$checkId ?>;
  var key = 'pf_followup_used_' + String(checkId || '0');

  var btn = document.getElementById('pfFollowUpBtn');
  var form = document.getElementById('pfFollowUpForm');
  var ta = document.getElementById('pfFollowUpText');
  var cancel = document.getElementById('pfFollowUpCancel');
  var done = document.getElementById('pfFollowUpDone');
  var submit = document.getElementById('pfFollowUpSubmit');

  function markUsed() { try { localStorage.setItem(key, '1'); } catch (e) {} }
  function isUsed()  { try { return localStorage.getItem(key) === '1'; } catch (e) { return false; } }

  if (checkId > 0 && isUsed()) {
    btn.disabled = true;
    done.style.display = 'block';
    return;
  }

  btn.addEventListener('click', function () {
    if (checkId > 0 && isUsed()) { return; }

    var headline = <?= json_encode((string)$headline) ?>;
    var created  = <?= json_encode((string)$created) ?>;

    var q = window.prompt('What is your follow‑up question?');
    if (!q) { return; }
    q = String(q).trim();
    if (!q) { return; }

    var text = ''
      + '[Follow‑up to a previous clarification]\n'
      + 'Clarification ID: ' + (checkId || '(unknown)') + '\n'
      + (created ? ('Created: ' + created + '\n') : '')
      + (headline ? ('Headline: ' + headline + '\n') : '')
      + '\n'
      + 'Follow‑up question:\n'
      + q + '\n';

    ta.value = text;
    form.style.display = 'block';
    ta.focus();
  });

  cancel.addEventListener('click', function () {
    form.style.display = 'none';
    ta.value = '';
  });

  form.addEventListener('submit', function () {
    if (checkId > 0) { markUsed(); }
    btn.disabled = true;
    if (done) { done.style.display = 'block'; }
    if (submit) { submit.disabled = true; }
  });
})();
</script>
