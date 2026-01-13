<?php declare(strict_types=1);

require_once PF_ROOT . '/app/bootstrap.php';

$enabled  = (pf_env('PF_DEBUG_TOOLS', '0') === '1');
$token    = (string)pf_env('PF_DEBUG_TOKEN', '');
$reqToken = (string)($_GET['t'] ?? $_POST['t'] ?? '');

if (!$enabled) { pf_http_error(404, 'Not Found'); }
if ($token === '' || !hash_equals($token, $reqToken)) { pf_http_error(404, 'Not Found'); }

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$defaultText = "Test message " . gmdate('c');
$text    = (string)($_POST['text'] ?? $defaultText);
$channel = (string)($_POST['channel'] ?? 'web-clarify');

$debugDbEnabled = (pf_env('PF_DEBUG_DB_CHECK', '0') === '1');

$result = null;
$dbCheck = null;

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $payload = ['text' => $text, 'channel' => $channel];

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $url    = $scheme . '://' . $host . '/ingest/web';

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        if ($ch === false) { throw new RuntimeException('curl_init failed'); }

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

        // --- DB verify (optional) ---
        if ($debugDbEnabled && $code === 200 && is_string($result['body']) && $result['body'] !== '') {
            $decoded = json_decode($result['body'], true);
            $traceId = (string)($decoded['trace_id'] ?? '');

            if ($traceId !== '') {
                $pdo = pf_pdo();
                $stmt = $pdo->prepare("
                    SELECT id, trace_id, channel, status, attempts, created_at
                    FROM pf_inbound_queue
                    WHERE trace_id = :trace_id
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmt->execute([':trace_id' => $traceId]);
                $row = $stmt->fetch();

                if (is_array($row)) {
                    $dbCheck = [
                        'found'     => true,
                        'id'        => (string)$row['id'],
                        'trace_id'  => (string)$row['trace_id'],
                        'channel'   => (string)$row['channel'],
                        'status'    => (string)$row['status'],
                        'attempts'  => (string)$row['attempts'],
                        'created_at'=> (string)$row['created_at'],
                    ];
                } else {
                    $dbCheck = ['found' => false, 'trace_id' => $traceId];
                }
            }
        }
    } catch (Throwable $e) {
        $result = ['error' => $e->getMessage()];
    }
}

// UI
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
    <p class="small" style="margin-top:6px;">Raw response from <code>/ingest/web</code>.</p>
';

if (is_array($result)) {
    $pretty = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $right .= '<pre style="white-space:pre-wrap;word-break:break-word;margin:12px 0 0 0;padding:12px;border-radius:12px;border:1px solid var(--pf-border);background:var(--pf-bg);">' . $esc((string)$pretty) . '</pre>';
} else {
    $right .= '<div class="small" style="margin-top:12px;padding:12px;border-radius:12px;border:1px dashed var(--pf-border);">
      No request sent yet. Fill the form and click <strong>Send</strong>.
    </div>';
}

if ($debugDbEnabled) {
    $right .= '<hr style="border:none;border-top:1px solid var(--pf-border);margin:16px 0;">';
    $right .= '<h2 class="card-title" style="margin:0;">DB Verify</h2>';
    $right .= '<p class="small" style="margin-top:6px;">Looks up the inbound row by <code>trace_id</code>.</p>';

    if (is_array($dbCheck)) {
        $right .= '<pre style="white-space:pre-wrap;word-break:break-word;margin:12px 0 0 0;padding:12px;border-radius:12px;border:1px solid var(--pf-border);background:var(--pf-bg);">' .
            $esc(json_encode($dbCheck, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) .
        '</pre>';
    } else {
        $right .= '<div class="small" style="margin-top:12px;padding:12px;border-radius:12px;border:1px dashed var(--pf-border);">
          DB verify is enabled. Send a request to populate this panel.
        </div>';
    }
}

$right .= '</div>';

$layout = '
  <div style="display:grid;grid-template-columns: 1fr; gap:16px;">
    <div class="small" style="margin-bottom:-6px;">
      <strong>URL:</strong> <code>/debug/post</code> (token required)
    </div>

    <div style="display:grid;grid-template-columns: 1fr; gap:16px;" class="pf-grid">
      ' . $left . '
      ' . $right . '
    </div>
  </div>

  <style>
    @media (min-width: 980px){
      .pf-grid{ grid-template-columns: 1.1fr .9fr !important; }
    }
  </style>
';

pf_render_basic_page('Debug POST Test', $layout);
