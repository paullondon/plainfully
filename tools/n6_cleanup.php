<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: httpdocs/tools/n6_cleanup.php
 * Purpose:
 *   N6 — housekeeping cron.
 *
 * What it does (safe defaults):
 *   1) Deletes expired result_access_tokens (security + hygiene).
 *   2) Releases stale queue locks and re-queues stale "sending" rows.
 *   3) Marks long-stuck rows as error after max attempts (optional).
 *
 * Run (example):
 *   /usr/bin/php /var/www/vhosts/plainfully.com/httpdocs/tools/n6_cleanup.php >> /var/www/vhosts/plainfully.com/logs/n6_cleanup.log 2>&1
 *
 * ENV (optional):
 *   EMAIL_QUEUE_LOCK_TTL_SECONDS=300
 *   EMAIL_QUEUE_MAX_ATTEMPTS=3
 * ============================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

date_default_timezone_set('UTC');

$ROOT = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

/** Minimal .env loader (does not override existing env). */
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

require_once $ROOT . '/app/support/db.php';

if (!function_exists('pf_env_int')) {
    function pf_env_int(string $k, int $default): int
    {
        $v = getenv($k);
        if ($v === false || $v === '') { return $default; }
        return (int)$v;
    }
}

$pdo = pf_db();
if (!($pdo instanceof PDO)) {
    fwrite(STDERR, "ERROR: DB connection failed.\n");
    exit(2);
}

$lockTtl     = max(30, pf_env_int('EMAIL_QUEUE_LOCK_TTL_SECONDS', 300));
$maxAttempts = max(1, pf_env_int('EMAIL_QUEUE_MAX_ATTEMPTS', 3));

$counts = [
    'tokens_deleted' => 0,
    'locks_released' => 0,
    'stuck_errored'  => 0,
];

try {
    // 1) Expired tokens
    $del = $pdo->prepare('DELETE FROM result_access_tokens WHERE expires_at <= NOW()');
    $del->execute();
    $counts['tokens_deleted'] = (int)$del->rowCount();
} catch (Throwable $e) {
    error_log('n6_cleanup: token delete failed: ' . $e->getMessage());
}

try {
    // 2) Release stale locks + requeue stale sending rows
    $unlock = $pdo->prepare('
        UPDATE inbound_queue
        SET status   = CASE WHEN status = "sending" THEN "queued" ELSE status END,
            locked_at = NULL,
            locked_by = NULL
        WHERE locked_at IS NOT NULL
          AND locked_at < (NOW() - INTERVAL :ttl SECOND)
    ');
    $unlock->bindValue(':ttl', $lockTtl, PDO::PARAM_INT);
    $unlock->execute();
    $counts['locks_released'] = (int)$unlock->rowCount();
} catch (Throwable $e) {
    error_log('n6_cleanup: unlock failed: ' . $e->getMessage());
}

try {
    // 3) Mark over-attempted rows as error (so they stop blocking queue metrics)
    $stuck = $pdo->prepare('
        UPDATE inbound_queue
        SET status = "error",
            last_error = COALESCE(last_error, "max_attempts_reached"),
            reply_error = COALESCE(reply_error, "max_attempts_reached"),
            locked_at = NULL,
            locked_by = NULL
        WHERE status IN ("queued","prepped","sending")
          AND attempts >= :max_attempts
    ');
    $stuck->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);
    $stuck->execute();
    $counts['stuck_errored'] = (int)$stuck->rowCount();
} catch (Throwable $e) {
    error_log('n6_cleanup: stuck erroring failed: ' . $e->getMessage());
}

echo "OK n6_cleanup "
   . "tokens_deleted={$counts['tokens_deleted']} "
   . "locks_released={$counts['locks_released']} "
   . "stuck_errored={$counts['stuck_errored']}\n";
