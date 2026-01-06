<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: httpdocs/tools/queue_worker.php
 * Purpose:
 *   Queue worker (process queued inbound emails + send the THIN result email).
 *
 * Trace timeline:
 *   Uses inbound_queue.trace_id to log every step (if enabled).
 * ============================================================
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); echo "CLI only.\n"; exit(1); }

date_default_timezone_set('UTC');

$ROOT = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
require_once __DIR__ . '/../../bootstrap/app.php';

require_once $ROOT . '/app/support/trace.php';
require_once $ROOT . '/app/features/checks/ai_mode.php';
require_once $ROOT . '/app/support/email_templates.php';
require_once $ROOT . '/app/support/db.php';

/** Minimal .env loader (fail-open) */
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
            ) { $v = substr($v, 1, -1); }

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
    $config = require $appConfigPath;
    $GLOBALS['config'] = $config;
}

/** DB */
$pdo = pf_db();
if (!($pdo instanceof PDO)) { fwrite(STDERR, "ERROR: unable to get DB connection.\n"); exit(1); }

/** Mailer */
$mailerPath = $ROOT . '/app/support/mailer.php';
if (!is_readable($mailerPath)) { fwrite(STDERR, "ERROR: mailer.php not found at {$mailerPath}\n"); exit(1); }
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
    if (!is_readable($p)) { fwrite(STDERR, "ERROR: missing required file: {$p}\n"); exit(2); }
    require_once $p;
}

/** Reuse normaliser helper if present */
$hooksControllerPath = $ROOT . '/app/controllers/email_hooks_controller.php';
if (is_readable($hooksControllerPath)) { require_once $hooksControllerPath; }

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
 * Returns: [string $cleaned, int $used, int $cap, bool $truncated]
 */
if (!function_exists('pf_aggressive_trim_and_cap')) {
    function pf_aggressive_trim_and_cap(string $text, bool $isPaid): array
    {
        $cap = $isPaid ? 4000 : 1500;

        $t = str_replace(["\r\n", "\r"], "\n", $text);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $markers = ["\nOn ", "\nFrom: ", "\nSent: ", "\n-----Original Message-----", "\n> "];
        foreach ($markers as $m) {
            $pos = stripos($t, $m);
            if ($pos !== false && $pos > 0) { $t = substr($t, 0, $pos); break; }
        }

        $t = preg_replace("/\n{3,}/", "\n\n", (string)$t);
        $t = preg_replace("/[ \t]{2,}/", " ", (string)$t);
        $t = trim((string)$t);

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

/**
 * Create a result-access token for Flow B and store ONLY hashed values.
 */
if (!function_exists('pf_create_result_access_token')) {
    function pf_create_result_access_token(PDO $pdo, int $checkId, string $recipientEmail): ?string
    {
        $pepper = (string)(getenv('RESULT_TOKEN_PEPPER') ?: '');
        if ($pepper === '') { error_log('RESULT_TOKEN_PEPPER missing; cannot create result token'); return null; }

        $ttlDays = (int)(getenv('RESULT_LINK_TTL_DAYS') ?: 28);
        if ($ttlDays < 1) { $ttlDays = 1; }
        if ($ttlDays > 90) { $ttlDays = 90; }

        $emailNorm = strtolower(trim($recipientEmail));
        if ($emailNorm === '' || !filter_var($emailNorm, FILTER_VALIDATE_EMAIL)) { return null; }

        $uStmt = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
        $uStmt->execute([':e' => $emailNorm]);
        $userId = (int)($uStmt->fetchColumn() ?: 0);
        if ($userId <= 0) { return null; }

        $raw = random_bytes(32);
        $token = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $tokenHash = hash_hmac('sha256', $token, $pepper);
        $recipientHash = hash_hmac('sha256', $emailNorm, $pepper);

        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $ttlDays . ' days')
            ->format('Y-m-d H:i:s');

        try {
            $ins = $pdo->prepare('
                INSERT INTO result_access_tokens (check_id, user_id, token_hash, recipient_email_hash, expires_at)
                VALUES (:check_id, :user_id, :token_hash, :recipient_email_hash, :expires_at)
            ');
            $ins->execute([
                ':check_id' => $checkId,
                ':user_id' => $userId,
                ':token_hash' => $tokenHash,
                ':recipient_email_hash' => $recipientHash,
                ':expires_at' => $expiresAt,
            ]);
            return $token;
        } catch (Throwable $e) {
            error_log('Failed to insert result_access_token: ' . $e->getMessage());
            return null;
        }
    }
}

$batch       = max(1, pf_env_int('EMAIL_QUEUE_BATCH', 200));
$maxAttempts = max(1, pf_env_int('EMAIL_QUEUE_MAX_ATTEMPTS', 3));
$lockTtl     = max(30, pf_env_int('EMAIL_QUEUE_LOCK_TTL_SECONDS', 300));
$workerId    = pf_worker_id();

/** Release stale locks (TTL). */
try {
    $unlock = $pdo->prepare('
        UPDATE inbound_queue
        SET status = CASE WHEN status = "sending" THEN "queued" ELSE status END,
            locked_at = NULL,
            locked_by = NULL
        WHERE locked_at IS NOT NULL
          AND locked_at < (NOW() - INTERVAL :ttl SECOND)
    ');
    $unlock->bindValue(':ttl', $lockTtl, PDO::PARAM_INT);
    $unlock->execute();
} catch (Throwable $e) {}

/** Fetch candidates (FIFO) */
$stmt = $pdo->prepare('
    SELECT id, trace_id, mode, from_email, to_email, subject, raw_body, raw_is_html, normalised_text
    FROM inbound_queue
    WHERE status IN ("queued","prepped")
      AND locked_at IS NULL
      AND attempts < :max_attempts
    ORDER BY id ASC
    LIMIT :lim
');
$stmt->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
$stmt->bindValue(':lim', $batch, PDO::PARAM_INT);
$stmt->execute();

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($rows) || count($rows) === 0) { echo "OK worker: nothing to do.\n"; exit(0); }

$aiClient = new DummyAiClient();
$engine   = new CheckEngine($pdo, $aiClient);

$baseUrl = '';
if (is_array($config) && isset($config['app']['base_url'])) { $baseUrl = rtrim((string)$config['app']['base_url'], '/'); }
if ($baseUrl === '') { $baseUrl = rtrim((string)(getenv('APP_BASE_URL') ?: 'https://plainfully.com'), '/'); }

$processed = 0;

foreach ($rows as $row) {
    $id        = (int)($row['id'] ?? 0);
    $traceId   = (string)($row['trace_id'] ?? '');
    $mode      = (string)($row['mode'] ?? 'generic');
    $fromEmail = (string)($row['from_email'] ?? '');
    $toEmail   = (string)($row['to_email'] ?? '');
    $subject   = (string)($row['subject'] ?? '');
    $rawBody   = (string)($row['raw_body'] ?? '');
    $rawIsHtml = (int)($row['raw_is_html'] ?? 0) === 1;
    $normText  = (string)($row['normalised_text'] ?? '');

    if ($id <= 0 || $fromEmail === '') { continue; }
    if ($traceId === '') { $traceId = pf_trace_new_id(); } // safety

    try {
        pf_trace($pdo, $traceId, 'prep', 'info', false, 'pick', 'Worker picked row', [
            'queue_id' => $id,
            'mode' => $mode,
            'worker_id' => $workerId,
        ]);

        $claim = $pdo->prepare('
            UPDATE inbound_queue
            SET status = "sending",
                attempts = attempts + 1,
                locked_at = NOW(),
                locked_by = :locked_by,
                last_error = NULL,
                reply_error = NULL
            WHERE id = :id
              AND status IN ("queued","prepped")
              AND locked_at IS NULL
        ');
        $claim->execute([':locked_by' => $workerId, ':id' => $id]);
        if ($claim->rowCount() !== 1) { continue; }

        pf_trace($pdo, $traceId, 'prep', 'info', false, 'claimed', 'Claimed row', ['queue_id'=>$id]);

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
            } catch (Throwable $e) {}
        }

        $isPaid = false;
        if (function_exists('pf_is_unlimited_tier_for_email')) { $isPaid = (bool)pf_is_unlimited_tier_for_email($fromEmail); }

        pf_trace($pdo, $traceId, 'prep', 'info', false, 'tier', 'Tier determined', ['is_paid'=>$isPaid?1:0]);

        [$cleanedText, $charsUsed, $charsCap, $wasTruncated] = pf_aggressive_trim_and_cap($normText, $isPaid);

        pf_trace($pdo, $traceId, 'prep', 'info', false, 'cap', 'Caps applied', [
            'chars_used'=>$charsUsed,
            'chars_cap'=>$charsCap,
            'truncated'=>$wasTruncated?1:0,
        ]);

        try {
            $updTrunc = $pdo->prepare('UPDATE inbound_queue SET truncated_text = :t WHERE id = :id');
            $updTrunc->execute([':t' => $cleanedText, ':id' => $id]);
        } catch (Throwable $e) {}

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
                'trace_id' => $traceId,
                'to' => $toEmail,
                'input_chars_used' => $charsUsed,
                'input_chars_cap' => $charsCap,
                'input_truncated' => $wasTruncated,
            ]
        );

        pf_trace($pdo, $traceId, 'ai', 'info', false, 'ai_call', 'Calling AI client', [
            'client' => 'DummyAiClient',
            'channel' => (string)$channels['check_channel'],
            'is_paid' => $isPaid ? 1 : 0,
            'cleaned_preview' => substr($cleanedText, 0, 350), // only stored if TRACE_DEEP=1
        ]);

        $result  = $engine->run($input, $isPaid);
        $checkId = (int)($result->id ?? 0);

        pf_trace($pdo, $traceId, 'ai', 'info', false, 'ai_done', 'AI returned', [
            'check_id' => $checkId,
            'scam_risk_level' => (string)$result->scamRiskLevel,
            'headline_preview' => substr((string)$result->headline, 0, 200), // deep mode only
        ]);

        $token = pf_create_result_access_token($pdo, $checkId, $fromEmail);
        $viewUrl = ($token !== null) ? ($baseUrl . '/r/' . rawurlencode($token)) : ($baseUrl . '/login');

        $outSubject = ($result->scamRiskLevel === 'high')
            ? 'Plainfully — Possible scam risk flagged'
            : 'Plainfully — Your result is ready';

        $headline = (string)$result->headline;
        $riskLine = (string)$result->externalRiskLine;
        $topic    = (string)$result->externalTopicLine;

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

        pf_trace($pdo, $traceId, 'output', $emailSent ? 'info' : 'error', !$emailSent, 'send', $emailSent ? 'Result email sent' : 'Result email failed', [
            'queue_id' => $id,
            'email_channel' => (string)$channels['email_channel'],
            'error' => $emailSent ? null : (string)$mailError,
        ]);

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
            ':capsule'     => (string)$result->externalTopicLine,
            ':verdict'     => (string)$result->headline,
            ':is_scam'     => ($result->scamRiskLevel === 'high') ? 1 : 0,
            ':is_paid'     => $result->isPaid ? 1 : 0,
            ':sent'        => $emailSent ? 1 : 0,
            ':reply_error' => $emailSent ? null : (string)($mailError ?: 'send_failed'),
            ':last_error'  => $emailSent ? null : (string)($mailError ?: 'send_failed'),
            ':id'          => $id,
        ]);

        pf_trace($pdo, $traceId, 'cleanup', 'info', false, 'done', 'Finished row', [
            'queue_id' => $id,
            'status' => $finalStatus,
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
        } catch (Throwable $t) {}

        pf_trace($pdo, $traceId, 'cleanup', 'error', true, 'exception', 'Worker exception', [
            'queue_id' => $id,
            'err' => substr($e->getMessage(), 0, 300),
        ]);

        error_log("Worker failed for queue id {$id}: " . $e->getMessage());
    }
}

echo "OK worker processed={$processed}\n";
