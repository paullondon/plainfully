<?php declare(strict_types=1);
/**
 * tools/trace_cleanup_worker.php
 *
 * - Emails any new ALERT trace events (best-effort)
 * - Purges expired trace rows
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }

date_default_timezone_set('UTC');

$ROOT = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

require_once $ROOT . '/app/support/db.php';
require_once $ROOT . '/app/support/email_templates.php';

$mailerPath = $ROOT . '/app/support/mailer.php';
if (is_readable($mailerPath)) { require_once $mailerPath; }

$pdo = pf_db();
if (!($pdo instanceof PDO)) { fwrite(STDERR, "DB unavailable\n"); exit(1); }

$to = (string)(getenv('PLAINFULLY_TRACE_EMAIL_ALERTS_TO') ?: '');
$channel = (string)(getenv('PLAINFULLY_TRACE_EMAIL_CHANNEL') ?: 'noreply');

$alerts = [];
try {
    $stmt = $pdo->prepare('
        SELECT id, created_at, trace_id, stage, level, action, summary, data_json
        FROM trace_events
        WHERE alert = 1 AND emailed_at IS NULL
        ORDER BY id ASC
        LIMIT 50
    ');
    $stmt->execute();
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $alerts = []; }

if (is_array($alerts) && count($alerts) > 0 && $to !== '' && function_exists('pf_send_email')) {
    $subject = 'Plainfully — trace alerts';

    $rowsHtml = '';
    foreach ($alerts as $a) {
        $rowsHtml .= '<tr>'
            . '<td style="padding:6px 8px;border-top:1px solid #e5e7eb;">' . htmlspecialchars((string)$a['created_at'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px;border-top:1px solid #e5e7eb;">' . htmlspecialchars((string)$a['trace_id'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px;border-top:1px solid #e5e7eb;">' . htmlspecialchars((string)$a['stage'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px;border-top:1px solid #e5e7eb;">' . htmlspecialchars((string)$a['action'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px;border-top:1px solid #e5e7eb;">' . htmlspecialchars((string)$a['summary'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
    }

    $inner = '<p>New Plainfully trace alerts:</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px;">'
        . '<thead><tr>'
        . '<th align="left" style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">Time</th>'
        . '<th align="left" style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">Trace</th>'
        . '<th align="left" style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">Stage</th>'
        . '<th align="left" style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">Action</th>'
        . '<th align="left" style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">Summary</th>'
        . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table>';

    $html = function_exists('pf_email_template') ? pf_email_template($subject, $inner) : $inner;

    $text = "New Plainfully trace alerts:\n\n";
    foreach ($alerts as $a) {
        $text .= (string)$a['created_at'] . " | " . (string)$a['trace_id'] . " | " . (string)$a['stage'] . " | " . (string)$a['action'] . " | " . (string)$a['summary'] . "\n";
    }

    [$ok, $err] = pf_send_email($to, $subject, $html, $channel, $text);

    if ($ok) {
        try {
            $ids = array_map(static fn($x) => (int)($x['id'] ?? 0), $alerts);
            $ids = array_values(array_filter($ids, static fn($x) => $x > 0));
            if (count($ids) > 0) {
                $in = implode(',', $ids);
                $pdo->exec("UPDATE trace_events SET emailed_at = NOW() WHERE id IN ({$in})");
            }
        } catch (Throwable $e) { /* ignore */ }
    } else {
        error_log('trace alert email failed: ' . (string)$err);
    }
}

try {
    $purge = $pdo->prepare('DELETE FROM trace_events WHERE expires_at < NOW()');
    $purge->execute();
} catch (Throwable $e) { /* ignore */ }

echo "OK trace cleanup\n";
