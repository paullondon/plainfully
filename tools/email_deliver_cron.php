<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Email Deliver Cron (FIFO, safe, no-reply only)
 * ============================================================
 * Purpose:
 *  - Pull pf_outbound_queue rows where channel='email' and status='new'
 *  - Send via SMTP using no-reply@plainfully.com ONLY
 *  - Mark row as 'sent' on success
 *  - Requeue with backoff on failure
 *
 * Env required:
 *  - PF_SMTP_HOST
 *  - PF_SMTP_USER        (must be no-reply@plainfully.com)
 *  - PF_SMTP_PASS
 *
 * Env optional:
 *  - PF_SMTP_PORT=465
 *  - PF_SMTP_ENCRYPTION=ssl   (ssl|tls|none)
 *  - PF_SMTP_FROM=no-reply@plainfully.com
 *  - PF_SMTP_FROM_NAME=Plainfully
 *  - PF_DELIVER_BASE_URL=https://plainfully.com
 *  - PF_DELIVER_MAX_SECONDS=50
 *  - PF_DELIVER_MAX_ITEMS=30
 */

require_once __DIR__ . '/../app/bootstrap.php';

final class PfEmailDeliverCron
{
    private int $maxSeconds;
    private int $maxItems;
    private string $baseUrl;

    public function __construct()
    {
        $this->maxSeconds = $this->clampInt((int)(pf_env('PF_DELIVER_MAX_SECONDS', '50') ?? '50'), 5, 55);
        $this->maxItems   = $this->clampInt((int)(pf_env('PF_DELIVER_MAX_ITEMS', '30') ?? '30'), 1, 300);
        $this->baseUrl    = rtrim((string)pf_env('PF_DELIVER_BASE_URL', 'https://plainfully.com'), '/');
    }

    public function run(): int
    {
        $start = microtime(true);
        $sent = 0;

        pf_log('info', 'Email deliver cron start', [
            'max_seconds' => $this->maxSeconds,
            'max_items' => $this->maxItems,
        ]);

        while (true) {
            if ($sent >= $this->maxItems) break;
            if ((microtime(true) - $start) >= $this->maxSeconds) break;

            $did = $this->deliverOne();
            if (!$did) break;

            $sent++;
        }

        echo "Email deliver complete. sent={$sent}\n";
        pf_log('info', 'Email deliver cron end', ['sent' => $sent]);
        return $sent;
    }

    private function deliverOne(): bool
    {
        $pdo = pf_pdo();
        $pdo->beginTransaction();

        try {
            // Claim one outbound email job (FIFO)
            $sel = $pdo->prepare("
                SELECT id, trace_id, payload_json, attempts
                FROM pf_outbound_queue
                WHERE status='new' AND channel='email' AND available_at <= NOW()
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $sel->execute();
            $row = $sel->fetch();

            if (!is_array($row)) {
                $pdo->commit();
                return false;
            }

            $id       = (int)$row['id'];
            $traceId  = (string)$row['trace_id'];
            $attempts = (int)$row['attempts'];

            // Mark processing + increment attempts while locked
            $pdo->prepare("
                UPDATE pf_outbound_queue
                SET status='processing', attempts = attempts + 1
                WHERE id=:id
            ")->execute([':id' => $id]);

            $pdo->commit();

            // --- Outside txn: send email ---
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload)) $payload = [];

            $toRaw   = (string)($payload['deliver']['to'] ?? '');
            $toEmail = $this->extractEmail($toRaw);
            if ($toEmail === null) {
                return $this->requeue($id, $traceId, $attempts + 1, 'Missing/invalid deliver.to');
            }

            $subject = (string)($payload['deliver']['subject'] ?? 'Your Plainfully result');
            $path    = (string)($payload['deliver']['result_url'] ?? ('/r?trace_id=' . rawurlencode($traceId)));
            $link    = $this->baseUrl . $path;

            $html = $this->buildEmailHtml($link);

            $ok = $this->sendEmail($toEmail, $subject, $html);

            if (!$ok) {
                return $this->requeue($id, $traceId, $attempts + 1, 'Mailer returned false');
            }

            // Mark sent
            $pdo2 = pf_pdo();
            $pdo2->prepare("UPDATE pf_outbound_queue SET status='sent' WHERE id=:id")->execute([':id' => $id]);

            pf_log('info', 'Email delivered', ['id' => $id, 'trace_id' => $traceId, 'to' => $toEmail]);
            return true;

        } catch (Throwable $e) {
            $pdo->rollBack();
            pf_log('error', 'Email deliver crashed', ['err' => $e->getMessage()]);
            return false;
        }
    }

    private function requeue(int $id, string $traceId, int $attemptsNow, string $reason): bool
    {
        // Exponential-ish backoff: 30s, 60s, 120s, 240s… cap at 15 mins
        $delay = 30 * (2 ** max(0, min(5, $attemptsNow - 1)));
        if ($delay > 900) $delay = 900;

        $pdo = pf_pdo();
        $pdo->prepare("
            UPDATE pf_outbound_queue
            SET status='new',
                available_at = DATE_ADD(NOW(), INTERVAL :delay SECOND)
            WHERE id=:id
        ")->execute([':id' => $id, ':delay' => $delay]);

        pf_log('error', 'Email deliver failed (requeued)', [
            'id' => $id,
            'trace_id' => $traceId,
            'attempts' => $attemptsNow,
            'delay_s' => $delay,
            'reason' => $reason,
        ]);

        return true; // keep loop alive
    }

    private function extractEmail(string $fromHeader): ?string
    {
        $s = trim($fromHeader);
        if ($s === '') return null;

        // "Name <email@domain>"
        if (preg_match('/<([^>]+)>/', $s, $m)) {
            $s = trim((string)$m[1]);
        }

        $s = strtolower($s);
        return filter_var($s, FILTER_VALIDATE_EMAIL) ? $s : null;
    }

    private function buildEmailHtml(string $link): string
    {
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

        // MVP body (we’ll swap to your full branded template after send is proven)
        return '
            <div style="font-family:Arial, sans-serif; line-height:1.5;">
              <h2>Your Plainfully result is ready</h2>
              <p>Open your result here:</p>
              <p><a href="' . $safeLink . '">' . $safeLink . '</a></p>
              <hr>
              <p style="color:#666;font-size:12px;">
                This is an automated message from no-reply@plainfully.com. Replies are not monitored.
              </p>
            </div>
        ';
    }

    private function sendEmail(string $to, string $subject, string $html): bool
    {
        // Prefer your project mailer if it exists
        if (function_exists('pf_mail_send')) {
            return (bool)pf_mail_send($to, $subject, $html, [
                'X-Plainfully-Origin' => 'outbound',
                'Auto-Submitted'      => 'auto-generated',
            ]);
        }

        // Fallback: PHPMailer if available (commonly present in your stack)
        if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            return $this->sendViaPhpMailer($to, $subject, $html);
        }

        pf_log('error', 'No mailer available (pf_mail_send missing and PHPMailer not found)', []);
        return false;
    }

    private function sendViaPhpMailer(string $to, string $subject, string $html): bool
    {
        $host = (string)pf_env('PF_SMTP_HOST', '');
        $user = (string)pf_env('PF_SMTP_USER', '');
        $pass = (string)pf_env('PF_SMTP_PASS', '');

        $port = (int)(pf_env('PF_SMTP_PORT', '465') ?? '465');
        $enc  = strtolower((string)pf_env('PF_SMTP_ENCRYPTION', 'ssl'));

        $from = (string)pf_env('PF_SMTP_FROM', $user);
        $name = (string)pf_env('PF_SMTP_FROM_NAME', 'Plainfully');

        if ($host === '' || $user === '' || $pass === '') {
            pf_log('error', 'SMTP env missing (PF_SMTP_HOST/USER/PASS)', []);
            return false;
        }

        // Enforce no-reply only (loop prevention)
        if (strtolower($from) !== 'no-reply@plainfully.com' && strtolower($user) !== 'no-reply@plainfully.com') {
            pf_log('error', 'SMTP must use no-reply@plainfully.com only', ['from' => $from, 'user' => $user]);
            return false;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = $pass;
            $mail->Port = $port;

            if ($enc === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($enc === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($from, $name);
            $mail->addAddress($to);

            // Loop prevention + standards-ish headers
            $mail->addCustomHeader('X-Plainfully-Origin', 'outbound');
            $mail->addCustomHeader('Auto-Submitted', 'auto-generated');

            // No reply loop: keep reply-to at no-reply
            $mail->addReplyTo('no-reply@plainfully.com', 'Plainfully');

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = strip_tags($html);

            return $mail->send();
        } catch (Throwable $e) {
            pf_log('error', 'PHPMailer send failed', ['err' => $e->getMessage()]);
            return false;
        }
    }

    private function clampInt(int $v, int $min, int $max): int
    {
        if ($v < $min) return $min;
        if ($v > $max) return $max;
        return $v;
    }
}

try {
    (new PfEmailDeliverCron())->run();
} catch (Throwable $e) {
    pf_log('error', 'Email deliver cron top-level crash', ['err' => $e->getMessage()]);
    fwrite(STDERR, "Email deliver cron crashed: " . $e->getMessage() . "\n");
    exit(1);
}
