<?php declare(strict_types=1);
/** @var array $vm */
$vm = isset($vm) && is_array($vm) ? $vm : [];
$mode = (string)($vm['mode'] ?? 'list');
$k = htmlspecialchars((string)($vm['k'] ?? ''), ENT_QUOTES, 'UTF-8');
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<div class="pf-card" style="max-width:1000px;margin:20px auto;">
  <h1 style="margin:0 0 12px 0;">Trace Viewer</h1>
  <p style="margin:0 0 16px 0;color:var(--pf-text-muted);">
    Private debug view. Use <code>?k=TRACE_VIEW_KEY</code> if you’re not logged in.
  </p>

  <?php if ($mode === 'list'): ?>
    <form method="get" action="/trace" style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px 0;">
      <input type="hidden" name="k" value="<?= $k ?>">
      <input name="trace_id" placeholder="Paste trace_id…" style="flex:1;min-width:260px;padding:10px;border-radius:10px;border:1px solid var(--pf-border);background:var(--pf-surface);color:var(--pf-text);">
      <button class="pf-btn pf-btn-primary" type="submit">Open trace</button>
    </form>

    <h2 style="margin:0 0 10px 0;font-size:18px;">Recent traces</h2>

    <div style="overflow:auto;">
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr>
            <th style="text-align:left;padding:8px;border-bottom:1px solid var(--pf-border);">Trace</th>
            <th style="text-align:left;padding:8px;border-bottom:1px solid var(--pf-border);">Last event</th>
            <th style="text-align:right;padding:8px;border-bottom:1px solid var(--pf-border);">Events</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach (($vm['rows'] ?? []) as $r):
          $tid = (string)($r['trace_id'] ?? '');
          $href = '/trace?trace_id=' . rawurlencode($tid) . ($k !== '' ? ('&k=' . rawurlencode($k)) : '');
        ?>
          <tr>
            <td style="padding:8px;border-bottom:1px solid var(--pf-border);"><a href="<?= h($href) ?>"><?= h($tid) ?></a></td>
            <td style="padding:8px;border-bottom:1px solid var(--pf-border);"><?= h($r['last_at'] ?? '') ?></td>
            <td style="padding:8px;border-bottom:1px solid var(--pf-border);text-align:right;"><?= h($r['event_count'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  <?php else: ?>
    <?php $traceId = (string)($vm['trace_id'] ?? ''); $back = '/trace' . ($k !== '' ? ('?k=' . rawurlencode($k)) : ''); ?>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
      <a class="pf-btn pf-btn-secondary" href="<?= h($back) ?>">← Back</a>
      <div style="font-weight:600;">Trace: <code><?= h($traceId) ?></code></div>
    </div>

    <?php if (!empty($vm['queue'])): $q = $vm['queue']; ?>
      <div style="padding:12px;border:1px solid var(--pf-border);border-radius:12px;margin-bottom:14px;">
        <div><strong>Queue</strong>: #<?= h($q['id'] ?? '') ?> — <?= h($q['status'] ?? '') ?> (<?= h($q['mode'] ?? '') ?>)</div>
        <div style="color:var(--pf-text-muted);margin-top:6px;">
          <div><strong>From</strong>: <?= h($q['from_email'] ?? '') ?></div>
          <div><strong>Subject</strong>: <?= h($q['subject'] ?? '') ?></div>
          <div><strong>Created</strong>: <?= h($q['created_at'] ?? '') ?></div>
          <?php if (!empty($q['last_error'])): ?>
            <div style="margin-top:6px;color:#b91c1c;"><strong>Last error</strong>: <?= h($q['last_error'] ?? '') ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div style="overflow:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:14px;">
        <thead>
          <tr>
            <th style="text-align:left;padding:8px;border-bottom:1px solid var(--pf-border);">Time</th>
            <th style="text-align:left;padding:8px;border-bottom:1px solid var(--pf-border);">Level</th>
            <th style="text-align:left;padding:8px;border-bottom:1px solid var(--pf-border);">Stage</th>
            <th style="text-align:left;padding:8px;border-bottom:1px solid var(--pf-border);">Event</th>
            <th style="text-align:left;padding:8px;border-bottom:1px solid var(--pf-border);">Message</th>
            <th style="text-align:left;padding:8px;border-bottom:1px solid var(--pf-border);">Meta</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach (($vm['events'] ?? []) as $e):
          $meta = (string)($e['meta_json'] ?? '');
          $metaPretty = '';
          if ($meta !== '') {
            $decoded = json_decode($meta, true);
            $metaPretty = is_array($decoded)
              ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
              : $meta;
          }
        ?>
          <tr>
            <td style="padding:8px;border-bottom:1px solid var(--pf-border);white-space:nowrap;"><?= h($e['created_at'] ?? '') ?></td>
            <td style="padding:8px;border-bottom:1px solid var(--pf-border);"><?= h($e['level'] ?? '') ?></td>
            <td style="padding:8px;border-bottom:1px solid var(--pf-border);"><?= h($e['stage'] ?? '') ?></td>
            <td style="padding:8px;border-bottom:1px solid var(--pf-border);"><?= h($e['event_name'] ?? ($e['event'] ?? '')) ?></td>
            <td style="padding:8px;border-bottom:1px solid var(--pf-border);"><?= h($e['message'] ?? '') ?></td>
            <td style="padding:8px;border-bottom:1px solid var(--pf-border);">
              <?php if ($metaPretty !== ''): ?>
                <details><summary>view</summary>
                  <pre style="margin:8px 0 0;white-space:pre-wrap;word-break:break-word;"><?= h($metaPretty) ?></pre>
                </details>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
