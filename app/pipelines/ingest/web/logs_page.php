<?php declare(strict_types=1);

/**
 * ============================================================
 * Debug Tool: Logs Viewer
 * ============================================================
 * URL:
 *  - /debug/logs?t=TOKEN
 *
 * Query params:
 *  - f   = optional substring filter
 *  - n   = number of lines (default 300, max 2000)
 *  - file= optional log filename (default: app.log)
 *
 * Safety:
 *  - Enabled ONLY when PF_DEBUG_TOOLS=1
 *  - Requires PF_DEBUG_TOKEN via ?t=...
 *  - Reads ONLY from PF_LOG_DIR (or default /var/log/plainfully)
 *  - Blocks path traversal
 */

require_once PF_ROOT . '/app/bootstrap.php';

$enabled  = (pf_env('PF_DEBUG_TOOLS', '0') === '1');
$token    = (string)pf_env('PF_DEBUG_TOKEN', '');
$reqToken = (string)($_GET['t'] ?? '');

if (!$enabled) { pf_http_error(404, 'Not Found'); }
if ($token === '' || !hash_equals($token, $reqToken)) { pf_http_error(404, 'Not Found'); }

$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Config
$logDir = (string)pf_env('PF_LOG_DIR', '/var/log/plainfully');
$logDir = rtrim($logDir, '/');

// Inputs
$file = (string)($_GET['file'] ?? 'app.log');
$n    = (int)($_GET['n'] ?? 300);
$filt = trim((string)($_GET['f'] ?? ''));

// Clamp lines
if ($n < 50) $n = 50;
if ($n > 2000) $n = 2000;

// Very strict filename allowlist: letters, numbers, dot, dash, underscore only
if (!preg_match('/^[a-zA-Z0-9._-]{1,80}$/', $file)) {
    pf_http_error(404, 'Not Found');
}

$path = $logDir . '/' . $file;

// Ensure realpath stays inside logDir (prevents traversal + symlink games)
$realDir  = realpath($logDir) ?: $logDir;
$realFile = realpath($path);

if ($realFile === false || strpos($realFile, $realDir) !== 0) {
    pf_http_error(404, 'Not Found');
}

if (!is_readable($realFile)) {
    pf_http_error(404, 'Not Found');
}

/**
 * Read last N lines efficiently without loading whole file.
 */
function pf_tail_lines(string $filename, int $lines): array
{
    $fh = fopen($filename, 'rb');
    if ($fh === false) return [];

    $buffer = '';
    $chunkSize = 8192;
    $pos = 0;
    $lineCount = 0;

    fseek($fh, 0, SEEK_END);
    $filesize = ftell($fh);
    if ($filesize === false) { fclose($fh); return []; }

    while ($pos < $filesize && $lineCount <= $lines) {
        $read = min($chunkSize, $filesize - $pos);
        $pos += $read;

        fseek($fh, -$pos, SEEK_END);
        $chunk = fread($fh, $read);
        if ($chunk === false) break;

        $buffer = $chunk . $buffer;
        $lineCount = substr_count($buffer, "\n");
        if ($pos >= $filesize) break;
    }

    fclose($fh);

    $all = preg_split("/\r\n|\n|\r/", $buffer) ?: [];
    // Drop potential trailing empty
    if (count($all) && trim((string)end($all)) === '') array_pop($all);

    // Return last N
    if (count($all) > $lines) {
        $all = array_slice($all, -$lines);
    }
    return $all;
}

/**
 * Light masking for obvious sensitive patterns.
 */
function pf_mask_line(string $line): string
{
    // Mask long tokens (20+ alnum)
    $line = preg_replace('/\b[a-zA-Z0-9_\-]{20,}\b/', '[masked]', $line) ?? $line;

    // Mask emails
    $line = preg_replace('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', '[email]', $line) ?? $line;

    // Mask obvious "pass=" or "password" fields
    $line = preg_replace('/\b(pass|password|pwd)\s*[:=]\s*[^,\s"]+/i', '$1=[masked]', $line) ?? $line;

    return $line;
}

$lines = pf_tail_lines($realFile, $n);

// Apply filter + masking
$out = [];
foreach ($lines as $ln) {
    if ($filt !== '' && stripos($ln, $filt) === false) continue;
    $out[] = pf_mask_line($ln);
}

// Simple file switcher: list allowed files in dir (top 20)
$filesList = [];
try {
    $all = @scandir($realDir);
    if (is_array($all)) {
        foreach ($all as $fn) {
            if ($fn === '.' || $fn === '..') continue;
            if (!preg_match('/^[a-zA-Z0-9._-]{1,80}$/', $fn)) continue;
            $full = $realDir . '/' . $fn;
            if (is_file($full) && is_readable($full)) {
                $filesList[] = $fn;
                if (count($filesList) >= 20) break;
            }
        }
    }
} catch (Throwable $e) {
    // ignore listing errors
}

$queryBase = '/debug/logs?t=' . rawurlencode($reqToken);

$controls = '
<div class="card">
  <h1 class="card-title">Debug: Logs</h1>
  <p class="small">Reading: <code>' . $esc($realFile) . '</code></p>

  <form method="get" action="/debug/logs" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:12px;">
    <input type="hidden" name="t" value="' . $esc($reqToken) . '">

    <div style="min-width:220px;flex:1;">
      <label class="small">File</label><br>
      <input class="input" name="file" value="' . $esc($file) . '" style="width:100%;">
      ' . (!empty($filesList) ? '<div class="small" style="margin-top:6px;">Quick pick: ' . implode(' · ', array_map(
            fn($fn) => '<a href="' . $esc($queryBase . '&file=' . rawurlencode($fn) . '&n=' . $n . '&f=' . rawurlencode($filt)) . '">' . $esc($fn) . '</a>',
            $filesList
        )) . '</div>' : '') . '
    </div>

    <div style="width:140px;">
      <label class="small">Lines</label><br>
      <input class="input" name="n" value="' . $esc((string)$n) . '" style="width:100%;">
    </div>

    <div style="min-width:220px;flex:1;">
      <label class="small">Filter contains</label><br>
      <input class="input" name="f" value="' . $esc($filt) . '" placeholder="e.g. ERROR or trace_id" style="width:100%;">
    </div>

    <button class="btn" type="submit">Refresh</button>
    <a class="btn" href="' . $esc($queryBase) . '">Reset</a>
  </form>
</div>
';

$logBox = '<div class="card">
  <h2 class="card-title" style="margin:0;">Output</h2>
  <p class="small" style="margin-top:8px;">Showing ' . $esc((string)count($out)) . ' line(s) (tail=' . $esc((string)$n) . ').</p>
  <pre style="white-space:pre-wrap;word-break:break-word;margin-top:12px;padding:14px;border-radius:12px;border:1px solid var(--pf-border);background:var(--pf-bg);max-height:70vh;overflow:auto;">'
    . $esc(implode("\n", $out)) .
  '</pre>
</div>';

pf_render_basic_page('Debug Logs', '<div style="display:grid;gap:16px;">' . $controls . $logBox . '</div>');
