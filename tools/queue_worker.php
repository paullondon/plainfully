<?php declare(strict_types=1);
/**
 * tools/email_queue_worker.php
 *
 * Plainfully — queue worker (process queued inbound emails + send final result).
 *
 * Intended cron (example):
 *   * * * * * /usr/bin/php /var/www/vhosts/plainfully.com/httpdocs/tools/email_queue_worker.php >> /var/www/vhosts/plainfully.com/logs/email_queue_worker.log 2>&1
 *
 * Single-pass worker:
 * - claims rows from inbound_queue where status='queued'
 * - normalises subject + raw_body into normalised_text
 * - runs CheckEngine
 * - emails result back
 * - stores AI outputs (capsule_text, short_verdict, is_scam, is_paid) and marks status done/error
 *
 * ENV optional:
 *   EMAIL_QUEUE_BATCH=200
 *   EMAIL_QUEUE_MAX_ATTEMPTS=3
 *   EMAIL_QUEUE_LOCK_TTL_SECONDS=300
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

date_default_timezone_set('UTC');

$ROOT = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

/** Minimal .env loader */
if (!function_exists('pf_load_env_file')) {
    function pf_load_env_file(string $path): void
    {
        if (!is_readable($path)) { return; }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) { return; }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) { continue; }
            if (!str_contains($line, '=')) { continue; }

            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);

            if (
                (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
                (str_starts_with($v, "'") && str_ends_with($v, "'"))
            ) {
                $v = substr($v, 1, -1);
            }

            if ($k !== '' && getenv($k) === false) {
                putenv($k . '=' . $v);
                $_ENV[$k] = $v;
            }
        }
    }
}
pf_load_env_file($ROOT . '/.env');

/** Optional config include */
$config = $GLOBALS['config'] ?? null;
$appConfigPath = $ROOT . '/config/app.php';
if ($config === null && is_readable($appConfigPath)) {
    /** @noinspection PhpIncludeInspection */
    $config = require $appConfigPath;
    $GLOBALS['config'] = $config;
}

/** DB helper */
require_once __DIR__ . '/../app/support/db.php';
$pdo = pf_db();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "ERROR: unable to get DB connection.\n");
    exit(1);
}

/** Mailer */
$mailerPath = $ROOT . '/app/support/mailer.php';
if (!is_readable($mailerPath)) {
    fwrite(STDERR, "ERROR: mailer.php not found at {$mailerPath}\n");
    exit(1);
}
require_once $mailerPath;

/** Feature classes (no composer) */
$files = [
    $ROOT . '/app/features/checks/check_input.php',
    $ROOT . '/app/features/checks/check_result.php',
    $ROOT . '/app/features/checks/ai_client.php',
    $ROOT . '/app/features/checks/check_engine.php',
    $ROOT . '/app/features/checks/dummy_ai_client.php',
];
foreach ($files as $p) {
    if (!is_readable($p)) {
        fwrite(STDERR, "ERROR: missing required file: {$p}\n");
        exit(2);
    }
    require_once $p;
}

/** Reuse normaliser helper if present */
$hooksControllerPath = $ROOT . '/app/controllers/email_hooks_controller.php';
if (is_readable($hooksControllerPath)) {
    require_once $hooksControllerPath;
}

use App\Features\Checks\CheckInput;
use App\Features\Checks\CheckEngine;
use App\Features\Checks\DummyAiClient;

if (!function_exists('pf_env_int')) {
    function pf_env_int(string $k, int $default): int
    {
        $v = getenv($k);
        if ($v === false || $v === '') { return $default; }
        return (int)$v;
    }
}

if (!function_exists('pf_mode_to_channels')) {
    function pf_mode_to_channels(string $mode): array
    {
        if ($mode === 'scamcheck') { return ['check_channel' => 'email-scamcheck', 'email_channel' => 'scamcheck']; }
        if ($mode === 'clarify')   { return ['check_channel' => 'email-clarify',   'email_channel' => 'clarify']; }
        return ['check_channel' => 'email', 'email_channel' => 'noreply'];
    }
}

if (!function_exists('pf_worker_id')) {
    function pf_worker_id(): string
    {
        $host = gethostname() ?: 'host';
        $pid  = getmypid() ?: 0;
        return substr($host, 0, 40) . ':' . (string)$pid;
    }
}

$batch       = max(1, pf_env_int('EMAIL_QUEUE_BATCH', 200));
$maxAttempts = max(1, pf_env_int('EMAIL_QUEUE_MAX_ATTEMPTS', 3));
$lockTtl     = max(30, pf_env_int('EMAIL_QUEUE_LOCK_TTL_SECONDS', 300));
$workerId    = pf_worker_id();

/** Release stale locks (best-effort) */
try {
    $unlock = $pdo->prepare('
        UPDATE inbound_queue
        SET locked_at = NULL,
            locked_by = NULL
        WHERE locked_at IS NOT NULL
          AND locked_at < (NOW() - INTERVAL :ttl SECOND)
    ');
    $unlock->bindValue(':ttl', $lockTtl, PDO::PARAM_INT);
    $unlock->execute();
} catch (Throwable $e) {
    // fail-open
}

/** Fetch candidates (FIFO) */
$stmt = $pdo->prepare('
    SELECT id, mode, from_email, to_email, subject, raw_body, raw_is_html, normalised_text
    FROM inbound_queue
    WHERE status = "queued"
      AND locked_at IS NULL
      AND attempts < :max_attempts
    ORDER BY id ASC
    LIMIT :lim
');
$stmt->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
$stmt->bindValue(':lim', $batch, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

if (!is_array($rows) || count($rows) === 0) {
    echo "OK worker: nothing to do.\n";
    exit(0);
}

$aiClient = new DummyAiClient();
$engine   = new CheckEngine($pdo, $aiClient);

$baseUrl = '';
if (is_array($config) && isset($config['app']['base_url'])) {
    $baseUrl = rtrim((string)$config['app']['base_url'], '/');
}
if ($baseUrl === '') {
    $baseUrl = rtrim((string)(getenv('APP_BASE_URL') ?: 'https://plainfully.com'), '/');
}

$processed = 0;

foreach ($rows as $row) {
    $id        = (int)($row['id'] ?? 0);
    $mode      = (string)($row['mode'] ?? 'generic');
    $fromEmail = (string)($row['from_email'] ?? '');
    $toEmail   = (string)($row['to_email'] ?? '');
    $subject   = (string)($row['subject'] ?? '');
    $rawBody   = (string)($row['raw_body'] ?? '');
    $rawIsHtml = (int)($row['raw_is_html'] ?? 0) === 1;
    $normText  = (string)($row['normalised_text'] ?? '');

    if ($id <= 0 || $fromEmail === '') { continue; }

    try {
        /** Claim row (lock + attempts) */
        $claim = $pdo->prepare('
            UPDATE inbound_queue
            SET status = "sending",
                attempts = attempts + 1,
                locked_at = NOW(),
                locked_by = :locked_by,
                last_error = NULL,
                reply_error = NULL
            WHERE id = :id
              AND status = "queued"
              AND locked_at IS NULL
        ');
        $claim->execute([':locked_by' => $workerId, ':id' => $id]);
        if ($claim->rowCount() !== 1) { continue; }

        /** Normalise + persist */
        if ($normText === '') {
            if (function_exists('plainfully_normalise_email_text')) {
                $normText = plainfully_normalise_email_text($subject, $rawBody, $fromEmail);
            } else {
                $normText = trim(($subject !== '' ? ($subject . "\n\n") : '') . $rawBody);
                if ($rawIsHtml) { $normText = strip_tags($normText); }
                $normText = html_entity_decode($normText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $normText = trim($normText);
            }

            try {
                $updNorm = $pdo->prepare('UPDATE inbound_queue SET normalised_text = :t WHERE id = :id');
                $updNorm->execute([':t' => $normText, ':id' => $id]);
            } catch (Throwable $e) {
                // ignore
            }
        }

        $channels = pf_mode_to_channels($mode);

        $input = new CheckInput(
            (string)$channels['check_channel'],
            $fromEmail,
            'text/plain',
            $normText,
            $fromEmail,
            null,
            ['queue_id' => $id, 'to' => $toEmail]
        );

        /** Paid flag: wire billing later; default false */
        $isPaid  = false;
        $result  = $engine->run($input, $isPaid);
        $checkId = (int)($result->id ?? 0);

        $viewUrl = $baseUrl . '/clarifications/view?id=' . $checkId;

        if ($mode === 'scamcheck') {
            $outSubject = 'Plainfully ScamCheck result';
            $intro      = 'We checked the message you forwarded to Plainfully ScamCheck.';
        } elseif ($mode === 'clarify') {
            $outSubject = 'Plainfully clarification result';
            $intro      = 'Here’s your Plainfully clarification summary.';
        } else {
            $outSubject = 'Plainfully check result';
            $intro      = 'Here’s the summary of the text you sent to Plainfully.';
        }

        $innerHtml =
            '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p><strong>Verdict:</strong> ' . htmlspecialchars((string)$result->shortVerdict, ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p><strong>Key things to know:</strong><br>' .
            nl2br(htmlspecialchars((string)$result->inputCapsule, ENT_QUOTES, 'UTF-8')) . '</p>' .
            '<p><a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '">View full details</a></p>';

        $htmlBody = function_exists('pf_email_template') ? pf_email_template($outSubject, $innerHtml) : $innerHtml;

        $textBody =
            $intro . "\n\n" .
            'Verdict: ' . (string)$result->shortVerdict . "\n\n" .
            "Key things to know:\n" . (string)$result->inputCapsule . "\n\n" .
            "View full details:\n" . $viewUrl . "\n";

        $emailSent = false;
        $mailError = null;

        if (function_exists('pf_send_email')) {
            [$emailSent, $mailError] = pf_send_email(
                $fromEmail,
                $outSubject,
                $htmlBody,
                (string)$channels['email_channel'],
                $textBody
            );
        } else {
            $mailError = 'pf_send_email helper not defined.';
        }

        $finalStatus = $emailSent ? 'done' : 'error';

        $upd2 = $pdo->prepare('
            UPDATE inbound_queue
            SET status        = :status,
                capsule_text  = :capsule,
                short_verdict = :verdict,
                is_scam        = :is_scam,
                is_paid        = :is_paid,
                reply_sent_at  = CASE WHEN :sent = 1 THEN NOW() ELSE reply_sent_at END,
                reply_error    = :reply_error,
                last_error     = :last_error,
                locked_at      = NULL,
                locked_by      = NULL
            WHERE id = :id
        ');
        $upd2->execute([
            ':status'      => $finalStatus,
            ':capsule'     => (string)$result->inputCapsule,
            ':verdict'     => (string)$result->shortVerdict,
            ':is_scam'     => $result->isScam ? 1 : 0,
            ':is_paid'     => $result->isPaid ? 1 : 0,
            ':sent'        => $emailSent ? 1 : 0,
            ':reply_error' => $emailSent ? null : (string)($mailError ?: 'send_failed'),
            ':last_error'  => $emailSent ? null : (string)($mailError ?: 'send_failed'),
            ':id'          => $id,
        ]);

        $processed++;

    } catch (Throwable $e) {
        try {
            $updE = $pdo->prepare('
                UPDATE inbound_queue
                SET status = "error",
                    last_error = :err,
                    reply_error = :err,
                    locked_at = NULL,
                    locked_by = NULL
                WHERE id = :id
            ');
            $updE->execute([':err' => substr($e->getMessage(), 0, 1000), ':id' => $id]);
        } catch (Throwable $t) {
            // ignore
        }
        error_log("Worker failed for queue id {$id}: " . $e->getMessage());
    }
}

echo "OK worker processed={$processed}\n";
