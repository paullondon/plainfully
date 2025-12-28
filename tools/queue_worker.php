<?php declare(strict_types=1);
/**
 * tools/email_queue_worker.php
 *
 * Plainfully — queue worker (process queued inbound emails + send final result).
 *
 * Single-pass worker:
 * - claims rows from inbound_queue where status='queued'
 * - normalises subject + raw_body into normalised_text
 * - applies aggressive trimming + hard caps (Free 1500 chars / Unlimited 4000 chars)
 * - runs CheckEngine (currently DummyAiClient or real AiClient when wired)
 * - sends THIN email (headline + scam risk level + one-line topic + link)
 * - stores minimal fields back to inbound_queue and marks status done/error
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

/**
 * Aggressive trimming + hard caps.
 *
 * This is intentionally conservative and cheap:
 * - strips tags
 * - decodes entities
 * - removes common reply-chain markers
 * - collapses whitespace
 * - enforces character caps (Free 1500 / Unlimited 4000)
 *
 * Returns: [string $cleaned, int $used, int $cap, bool $truncated]
 */
if (!function_exists('pf_aggressive_trim_and_cap')) {
    function pf_aggressive_trim_and_cap(string $text, bool $isPaid): array
    {
        $cap = $isPaid ? 4000 : 1500;

        // Normalize newlines early
        $t = str_replace(["\r\n", "\r"], "\n", $text);

        // Strip HTML tags and decode entities (safe, no external calls)
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove everything after common quoted-reply markers (best-effort)
        $markers = [
            "\nOn ",
            "\nFrom: ",
            "\nSent: ",
            "\n-----Original Message-----",
            "\n> ",
        ];
        foreach ($markers as $m) {
            $pos = stripos($t, $m);
            if ($pos !== false && $pos > 0) {
                $t = substr($t, 0, $pos);
                break;
            }
        }

        // Collapse whitespace but preserve paragraphs
        $t = preg_replace("/\n{3,}/", "\n\n", (string)$t);
        $t = preg_replace("/[ \t]{2,}/", " ", (string)$t);
        $t = trim((string)$t);

        // Enforce cap (UTF-8 safe)
        $used = mb_strlen($t, 'UTF-8');
        $truncated = false;
        if ($used > $cap) {
            $t = mb_substr($t, 0, $cap, 'UTF-8');
            $t = rtrim($t);
            $used = mb_strlen($t, 'UTF-8');
            $truncated = true;
        }

        return [$t, $used, $cap, $truncated];
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

// Dummy client for now. Swap to real OpenAI client when ready.
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

        /** Plan tier (current stub) */
        $isPaid = pf_is_unlimited_tier_for_email($fromEmail);

        /** Aggressive trimming + caps (per plan) */
        [$cleanedText, $charsUsed, $charsCap, $wasTruncated] = pf_aggressive_trim_and_cap($normText, $isPaid);

        $channels = pf_mode_to_channels($mode);

        $input = new CheckInput(
            (string)$channels['check_channel'],
            $fromEmail,
            'text/plain',
            $cleanedText,
            $fromEmail,
            null,
            [
                'queue_id' => $id,
                'to' => $toEmail,
                'input_chars_used' => $charsUsed,
                'input_chars_cap' => $charsCap,
                'input_truncated' => $wasTruncated,
            ]
        );

        $result  = $engine->run($input, $isPaid);
        $checkId = (int)($result->id ?? 0);

        $viewUrl = $baseUrl . '/clarifications/view?id=' . $checkId;

        // Subject line stays service-led (Plainfully is not a "scam checker").
        $outSubject = ($result->scamRiskLevel === 'high')
            ? 'Plainfully — Possible scam risk flagged'
            : 'Plainfully — Your result is ready';

        // Thin external response (email): no reasons, no web sections.
        $headline = (string)$result->headline;
        $riskLine = (string)$result->externalRiskLine;   // "Scam risk level: X (checked)"
        $topic    = (string)$result->externalTopicLine;  // one line

        $innerHtml =
            '<p><strong>' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</strong></p>' .
            '<p>' . htmlspecialchars($riskLine, ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p>' . htmlspecialchars($topic, ENT_QUOTES, 'UTF-8') . '</p>' .
            '<p><a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '">View your full result</a></p>';

        $htmlBody = function_exists('pf_email_template') ? pf_email_template($outSubject, $innerHtml) : $innerHtml;

        $textBody =
            $headline . "\n" .
            $riskLine . "\n\n" .
            $topic . "\n\n" .
            "View your full result:\n" . $viewUrl . "\n";

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

        // Persist minimal legacy fields for now
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
            ':capsule'     => (string)$result->externalTopicLine,
            ':verdict'     => (string)$result->headline,
            ':is_scam'     => ($result->scamRiskLevel === 'high') ? 1 : 0,
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