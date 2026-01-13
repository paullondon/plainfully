<?php declare(strict_types=1);

/**
 * helpers.php
 *
 * All shared helper functions live here (NO lone functions elsewhere).
 * Keep this file small and boring.
 */

function pf_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pf_http_error(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function pf_render_basic_page(string $title, string $innerHtml): void
{
    $titleEsc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html><html lang="en"><head>';
    echo '<meta charset="utf-8"/>';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1"/>';
    echo '<title>' . $titleEsc . '</title>';
    echo '<link rel="stylesheet" href="/assets/css/theme.css"/>';
    echo '<link rel="stylesheet" href="/assets/css/base.css"/>';
    echo '<link rel="stylesheet" href="/assets/css/components/buttons.css"/>';
    echo '<link rel="stylesheet" href="/assets/css/components/cards.css"/>';
    echo '</head><body><div class="container">';
    echo $innerHtml;
    echo '</div></body></html>';
    exit;
}

/**
 * Minimal structured logging to /storage/logs/app.log
 */
function pf_log(string $level, string $message, array $ctx = []): void
{
    $level = strtoupper($level);
    $ts = gmdate('c');

    // Redact common sensitive keys
    $redactKeys = ['password','pass','secret','token','apikey','api_key','authorization'];
    foreach ($ctx as $k => $v) {
        if (in_array(strtolower((string)$k), $redactKeys, true)) {
            $ctx[$k] = '[REDACTED]';
        }
    }

    $line = $ts . " [$level] " . $message;
    if (!empty($ctx)) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $line .= PHP_EOL;

    $logDir = PF_ROOT . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0750, true);
    }
    @file_put_contents($logDir . '/app.log', $line, FILE_APPEND);
}
