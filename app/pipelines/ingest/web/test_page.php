<?php declare(strict_types=1);

/**
 * ============================================================
 * Debug Tool: Web POST Test Page
 * ============================================================
 * Purpose:
 *  - Type text + channel
 *  - Sends POST JSON to /ingest/web
 *  - Shows response (status + body) in a separate panel
 *
 * Safety:
 *  - Enabled ONLY when PF_DEBUG_TOOLS=1
 *  - Requires PF_DEBUG_TOKEN via ?t=... (or POST hidden field)
 *  - Disable instantly by setting PF_DEBUG_TOOLS=0
 */

require_once PF_ROOT . '/app/bootstrap.php';

$enabled = (pf_env('PF_DEBUG_TOOLS', '0') === '1');
$token   = (string)pf_env('PF_DEBUG_TOKEN', '');
$reqToken = (string)($_GET['t'] ?? $_POST['t'] ?? '');

if (!$enabled) {
    pf_http_error(404, 'Not Found');
}
if ($token === '' || !hash_equals($token, $reqToken)) {
    pf_http_error(404, 'Not Found');
}

$defaultText = "Test message " . gmdate('c');
$text    = (string)($_POST['text'] ?? $defaultText);
$channel = (string)($_POST['channel'] ?? 'web-clarify');

$result = null;

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $payload = [
            'text'    => $text,
            'channel' => $channel,
        ];

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $url    = $scheme . '://' . $host . '/ingest/web';

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_TIMEOUT        => 20,
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $result = [
            'url'        => $url,
            'sent_json'  => $json,
            'http_code'  => $code,
            'curl_error' => $err,
            'body'       => ($body === false ? '' : $body),
        ];
    } catch (Throwable $e) {
        $result = [
            'error' => $e->getMessage(),
        ];
    }
}

// Build UI
$left = '
  <div class="card">
    <h1 class="card-title">Debug: POST Test</h1>
    <p class="small">
      Gated by <code>PF_DEBUG_TOOLS</code> + token.
      Disable by setting <code>PF_DEBUG_TOOLS=0</code>.
    </p>

    <form method="post" style="display:grid;gap:12px;margin-top:14px;">
      <input type="hidden" name="t" value="' . $esc($reqToken) . '"/>

      <div style="display:grid;gap:6px;">
        <label class="small">Channel</label>
        <input name="channel" value="' . $esc($channel) . '"
          style="padding:12px;border-radius:12px;border:1px solid var(--pf-border);background:var(--pf-surface);color:var(--pf-text);" />
        <div class="small">Examples: <code>web-clarify</code>, <code>email-clarify</code>, <code>email-scamcheck</code></div>
      </div>

      <div style="display:grid;gap:6px;">
        <label class="small">Text</label>
        <textarea name="text" rows="10"
          style="padding:12px;border-radius:12px;border:1px solid var(--pf-border);background:var(--pf-surface);color:var(--pf-text);width:100%;">' . $esc($text) . '</textarea>
        <div class="small">Posts JSON to <code>/ingest/web</code> and displays the raw response.</div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button class="btn btn-primary" type="submit">Send → /ingest/web</button>
        <a class="btn" href="/debug/post?t=' . $esc($reqToken) . '">Reset</a>
      </div>
    </form>
  </div>
';

$right = '
  <div class="card">
    <h2 class="card-title" style="margin:0;">Response</h2>
    <p class="small" style="margin-top:6px;">Status, payload, and raw body returned by <code>/ingest/web</code>.</p>
';

if (is_array($result)) {
    $pretty = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $right .= '<pre style="white-space:pre-wrap;word-break:break-word;margin:12px 0 0 0;padding:12px;border-radius:12px;border:1px solid var(--pf-border);background:var(--pf-bg);">' . $esc((string)$pretty) . '</pre>';
} else {
    $right .= '<div class="small" style="margin-top:12px;padding:12px;border-radius:12px;border:1px dashed var(--pf-border);">
      No request sent yet. Fill the form and click <strong>Send</strong>.
    </div>';
}

$right .= '</div>';

$layout = '
  <div style="display:grid;grid-template-columns: 1fr; gap:16px;">
    <div class="small" style="margin-bottom:-6px;">
      <strong>URL:</strong> <code>/debug/post</code> (token required)
    </div>

    <div style="display:grid;grid-template-columns: 1fr; gap:16px;"
         class="pf-grid">
      ' . $left . '
      ' . $right . '
    </div>
  </div>

  <style>
    /* Lightweight responsive tweak (kept inline for the debug tool only) */
    @media (min-width: 980px){
      .pf-grid{ grid-template-columns: 1.1fr .9fr !important; }
    }
  </style>
';

pf_render_basic_page('Debug POST Test', $layout);
