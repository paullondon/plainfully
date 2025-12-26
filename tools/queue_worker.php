<?php declare(strict_types=1);
/**
 * tools/email_queue_worker.php
 *
 * Plainfully — queue worker (process queued emails + send final result).
 *
 * Intended cron:
 *   * * * * * /usr/bin/php /var/www/vhosts/plainfully.com/httpdocs/tools/email_queue_worker.php >> /var/www/vhosts/plainfully.com/logs/email_queue_worker.log 2>&1
 *
 * What it does:
 * - Claims up to EMAIL_QUEUE_BATCH rows from `email_queue` (FIFO)
 * - Runs them through CheckEngine
 * - Emails result back to sender
 * - Updates queue row status
 *
 * ENV optional:
 *   EMAIL_QUEUE_BATCH=200
 *   EMAIL_QUEUE_MAX_ATTEMPTS=3
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

date_default_timezone_set('UTC');

$ROOT = realpath(__DIR__ . '/..') ?: __DIR__ . '/..';

// Minimal .env loader (same as poller)
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

            if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
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

// Optional config include
$config = $GLOBALS['config'] ?? null;
$appConfigPath = $ROOT . '/config/app.php';
if ($config === null && is_readable($appConfigPath)) {
    /** @noinspection PhpIncludeInspection */
    $config = require $appConfigPath;
    $GLOBALS['config'] = $config;
}

// DB helper (fallback)
if (!function_exists('pf_db')) {
    function pf_db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) { return $pdo; }

        $dsn  = getenv('DB_DSN') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';

        if ($dsn === '') {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $name = getenv('DB_NAME') ?: '';
            $charset = getenv('DB_CHARSET') ?: 'utf8mb4';
            if ($name === '') {
                throw new RuntimeException('DB_DSN or DB_NAME must be set.');
            }
            $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
        }

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $pdo;
    }
}

// Mailer + engine includes
$mailerPath = $ROOT . '/app/support/mailer.php';
if (is_readable($mailerPath)) {
    /** @noinspection PhpIncludeInspection */
    require_once $mailerPath;
} else {
    fwrite(STDERR, "ERROR: mailer.php not found at {$mailerPath}\n");
    exit(1);
}

// Feature classes (no composer)
$checkInputPath  = $ROOT . '/app/features/checks/check_input.php';
$checkResultPath = $ROOT . '/app/features/checks/check_result.php';
$aiClientPath    = $ROOT . '/app/features/checks/ai_client.php';
$enginePath      = $ROOT . '/app/features/checks/check_engine.php';
$dummyAiPath     = $ROOT . '/app/features/checks/dummy_ai_client.php';

foreach ([$checkInputPath, $checkResultPath, $aiClientPath, $enginePath, $dummyAiPath] as $p) {
    if (!is_readable($p)) {
        fwrite(STDERR, "ERROR: missing required file: {$p}\n");
        exit(2);
    }
    /** @noinspection PhpIncludeInspection */
    require_once $p;
}

// Reuse the HTML normaliser + helper functions from controller (optional but recommended)
$hooksControllerPath = $ROOT . '/app/controllers/email_hooks_controller.php';
if (is_readable($hooksControllerPath)) {
    /** @noinspection PhpIncludeInspection */
    require_once $hooksControllerPath;
}

// Namespaced classes
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
        // Maps queue.mode => CheckInput.channel + outgoing email channel
        if ($mode === 'scamcheck') { return ['check_channel' => 'email-scamcheck', 'email_channel' => 'scamcheck']; }
        if ($mode === 'clarify')   { return ['check_channel' => 'email-clarify',   'email_channel' => 'clarify']; }
        return ['check_channel' => 'email', 'email_channel' => 'noreply'];
    }
}

$batch       = max(1, pf_env_int('EMAIL_QUEUE_BATCH', 200));
$maxAttempts = max(1, pf_env_int('EMAIL_QUEUE_MAX_ATTEMPTS', 3));

$pdo = pf_db();

// Claim work (FIFO). We keep it simple: select then update each row to "processing".
$stmt = $pdo->prepare('
    SELECT *
    FROM email_queue
    WHERE status = "queued"
      AND available_at <= NOW()
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
    $bodyRaw   = (string)($row['body'] ?? '');

    if ($id <= 0 || $fromEmail === '') {
        continue;
    }

    try {
        // Mark as processing + increment attempts
        $upd = $pdo->prepare('
            UPDATE email_queue
            SET status="processing",
                attempts = attempts + 1,
                last_error = NULL
            WHERE id = :id
              AND status = "queued"
        ');
        $upd->execute([':id' => $id]);

        // Normalise input to safe visible text (prefers controller helper if available)
        if (function_exists('plainfully_normalise_email_text')) {
            $content = plainfully_normalise_email_text($subject, $bodyRaw, $fromEmail);
        } else {
            $content = trim($subject . "\n\n" . $bodyRaw);
            $content = strip_tags($content);
        }

        $channels = pf_mode_to_channels($mode);

        $input = new CheckInput(
            (string)$channels['check_channel'],
            $fromEmail,
            'text/plain',
            $content,
            $fromEmail,
            null,
            ['queue_id' => $id, 'to' => $toEmail]
        );

        // Paid flag: queue worker currently uses billing in the hook flow; here we treat as paid=false.
        // If you want to flip this based on plan, include your billing helpers and set this properly.
        $isPaid = false;

        $result = $engine->run($input, $isPaid);
        $checkId = (int)($result->id ?? 0);

        $viewUrl = $baseUrl . '/clarifications/view?id=' . $checkId;

        // Build email
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

        $htmlBody = function_exists('pf_email_template')
            ? pf_email_template($outSubject, $innerHtml)
            : $innerHtml;

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

        // Update row status
        $upd2 = $pdo->prepare('
            UPDATE email_queue
            SET status = :status,
                last_error = :err
            WHERE id = :id
        ');
        $upd2->execute([
            ':status' => $emailSent ? 'sent' : 'error',
            ':err'    => $emailSent ? null : (string)($mailError ?: 'send_failed'),
            ':id'     => $id,
        ]);

        $processed++;

    } catch (Throwable $e) {
        $updE = $pdo->prepare('
            UPDATE email_queue
            SET status="error",
                last_error = :err
            WHERE id = :id
        ');
        $updE->execute([
            ':err' => substr($e->getMessage(), 0, 1000),
            ':id'  => $id,
        ]);

        error_log("Worker failed for queue id {$id}: " . $e->getMessage());
    }
}

echo "OK worker processed={$processed}\n";
