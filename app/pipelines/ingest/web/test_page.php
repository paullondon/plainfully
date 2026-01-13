<?php declare(strict_types=1);

/**
 * ============================================================
 * Debug Tool: Web POST Test Page
 * ============================================================
 * Purpose:
 *  - Lets you type text + channel
 *  - Sends POST JSON to /ingest/web
 *  - Shows raw response (status + body)
 *
 * Safety:
 *  - Enabled ONLY when PF_DEBUG_TOOLS=1
 *  - Requires PF_DEBUG_TOKEN via ?t=... (or POST hidden field)
 *  - Easy to disable by flipping env flag
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

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $payload = [
            'text'    => $text,
            'channel' => $channel,
        ];

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

        // Call the same site endpoint (no external deps)
        $url = $scheme . '://' . $host . '/ingest/web';

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

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$out = '<div class="card">';
$out .= '<h1 class="card-title">Debug: POST Test</h1>';
$out .= '<p class="small">This tool is gated by <code>PF_DEBUG_TOOLS</code> + token. Disable by setting <code>PF_DEBUG_TOOLS=0</code>.</p>';

$out .= '<form method="post" style="display:grid;gap:12px;">';
$out .= '<input type="hidden" name="t" value="' . $esc($reqToken) . '"/>';

$out .= '<label class="small">Channel</label>';
$out .= '<input name="channel" value="' . $esc($channel) . '" style="padding:10px;border-radius:12px;border:1px solid var(--pf-border);"/>';

$out .= '<label class="small">Text</label>';
$out .= '<textarea name="text" rows="6" style="padding:10px;border-radius:12px;border:1px solid var(--pf-border);width:100%;">' . $esc($text) . '</textarea>';

$out .= '<button class="btn btn-primary" type="submit">Send POST → /ingest/web</button>';
$out .= '</form>';

if (is_array($result)) {
    $out .= '<hr style="border:none;border-top:1px solid var(--pf-border);margin:16px 0;">';
    $out .= '<div class="small"><strong>Response</strong></div>';
    $out .= '<pre style="white-space:pre-wrap;word-break:break-word;margin:10px 0 0 0;">' . $esc(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
}

$out .= '</div>';

pf_render_basic_page('Debug POST Test', $out);
