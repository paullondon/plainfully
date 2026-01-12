<?php declare(strict_types=1);

namespace App\Features\Lifecycle;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use Throwable;

/**
 * LifecycleMailer
 *
 * Sends lifecycle emails for Plainfully.
 *
 * IMPORTANT:
 * - If you already have a mailer wrapper, replace sendHtmlEmail()
 *   to call it, and keep the template methods unchanged.
 */
final class LifecycleMailer
{
    // -----------------------------
    // Public send methods
    // -----------------------------

    public function sendWelcome(string $toEmail): void
    {
        $subject = 'Welcome to Plainfully — what we’re here to help with';
        $html    = $this->wrapEmail($this->tplWelcome());
        $text    = $this->toText($html);

        $this->sendHtmlEmail($toEmail, $subject, $html, $text);
    }

    public function sendTips(string $toEmail): void
    {
        $subject = 'A few quick Plainfully hints (2 minutes)';
        $html    = $this->wrapEmail($this->tplTips());
        $text    = $this->toText($html);

        $this->sendHtmlEmail($toEmail, $subject, $html, $text);
    }

    public function sendUnderusePrompt(string $toEmail): void
    {
        $subject = 'You’ve still got free clarity waiting';
        $html    = $this->wrapEmail($this->tplUnderusePrompt());
        $text    = $this->toText($html);

        $this->sendHtmlEmail($toEmail, $subject, $html, $text);
    }

    public function sendDay20Feedback(string $toEmail, int $userId): void
    {
        $subject = 'Quick check-in — is Plainfully helping?';
        $html    = $this->wrapEmail($this->tplFeedbackDay20($userId));
        $text    = $this->toText($html);

        $this->sendHtmlEmail($toEmail, $subject, $html, $text);
    }

    public function sendSingleDayFollowup(string $toEmail, int $userId): void
    {
        $subject = 'How did that feel? (and a small thank-you)';
        $html    = $this->wrapEmail($this->tplSingleDayFollowup($userId));
        $text    = $this->toText($html);

        $this->sendHtmlEmail($toEmail, $subject, $html, $text);
    }

    public function sendDirectDebitCheckin(string $toEmail, int $userId): void
    {
        $subject = 'How’s Plainfully going so far?';
        $html    = $this->wrapEmail($this->tplDirectDebitCheckin($userId));
        $text    = $this->toText($html);

        $this->sendHtmlEmail($toEmail, $subject, $html, $text);
    }

    public function sendMonthlyReview(string $toEmail, int $userId): void
    {
        $subject = 'Clarification Engine Review — quick monthly check-in';
        $html    = $this->wrapEmail($this->tplMonthlyReview($userId));
        $text    = $this->toText($html);

        $this->sendHtmlEmail($toEmail, $subject, $html, $text);
    }

    // -----------------------------
    // Mail transport (PHPMailer)
    // -----------------------------

    /**
     * Send an HTML email securely via SMTP using env vars.
     *
     * Replace this method if you already have a working mailer wrapper.
     */
    private function sendHtmlEmail(string $toEmail, string $subject, string $html, string $text): void
    {
        $host = (string) getenv('MAIL_HOST');
        $port = (int) (getenv('MAIL_PORT') ?: 587);
        $user = (string) getenv('MAIL_NOREPLY_USER');
        $pass = (string) getenv('MAIL_NOREPLY_PASS');
        $sec  = (string) getenv('MAIL_ENCRYPTION'); // 'tls' | 'ssl' | ''
        $from = (string) getenv('MAIL_NOREPLY_USER');
        $name = (string) getenv('MAIL_FROM_NAME');

        // Basic guardrails (fail closed; log; let cron retry)
        if ($host === '' || $from === '') {
            throw new \RuntimeException('MAIL_HOST/MAIL_NOREPLY_USER missing in env');
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->SMTPAuth   = ($user !== '' && $pass !== '');

            if ($mail->SMTPAuth) {
                $mail->Username = $user;
                $mail->Password = $pass;
            }

            if ($sec === 'tls' || $sec === 'ssl') {
                $mail->SMTPSecure = $sec;
            }

            $mail->CharSet = 'UTF-8';

            $mail->setFrom($from, $name !== '' ? $name : 'Plainfully');
            $mail->addAddress($toEmail);

            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body    = $html;
            $mail->AltBody = $text;

            $mail->send();
        } catch (MailException $e) {
            throw new \RuntimeException('Email send failed: ' . $e->getMessage());
        } catch (Throwable $e) {
            throw $e;
        }
    }

    // -----------------------------
    // Signed feedback links
    // -----------------------------

    /**
     * Build a signed feedback URL:
     *   /feedback?u=ID&k=KIND&r=RATING&s=SIG
     *
     * Signature uses APP_KEY and HMAC-SHA256 over: "u|k|r"
     */
    private function feedbackUrl(int $userId, string $kind, string $rating): string
    {
        $base = rtrim((string) getenv('APP_URL'), '/');
        if ($base === '') { $base = 'https://plainfully.com'; }

        $appKey = (string) getenv('APP_KEY');
        if ($appKey === '') {
            // Fail closed: links won't verify without APP_KEY.
            // Still return something deterministic to avoid hard crash.
            $sig = 'missing_app_key';
        } else {
            $sig = hash_hmac('sha256', $userId . '|' . $kind . '|' . $rating, $appKey);
        }

        return $base . '/feedback?u=' . $userId . '&k=' . rawurlencode($kind) . '&r=' . rawurlencode($rating) . '&s=' . rawurlencode($sig);
    }

    // -----------------------------
    // Templates (calm, clarity-first)
    // -----------------------------

    private function tplWelcome(): string
    {
        return '
            <h1>Welcome to Plainfully</h1>
            <p>Plainfully exists to bring <strong>clarity</strong> to things that are hard to read, confusing, or stressful — emails, letters, messages, and documents full of jargon.</p>

            <h2>How it works</h2>
            <ol>
              <li><strong>Safety first (quietly).</strong> We check whether it shows signs of being a scam or phishing attempt.</li>
              <li><strong>Then clarity.</strong> We explain what it means in plain English and what (if anything) you should do next.</li>
            </ol>

            <h2>One helpful thing to know</h2>
            <p>If your email provider blocks a message before it reaches Plainfully, that usually means it was already identified as high-risk. That’s a good thing — your provider stepped in early.</p>
            <p>If that happens, you can still copy and paste the content into a new email, or attach the original email as a <code>.eml</code> file.</p>

            <p style="margin-top:24px;">— Plainfully<br><span class="muted">Clarity first. Reassurance always.</span></p>
        ';
    }

    private function tplTips(): string
    {
        return '
            <h1>Quick hints (2 minutes)</h1>

            <h2>Plainfully is best for</h2>
            <ul>
              <li>Letters that feel “official” but don’t actually say what they want</li>
              <li>Contracts / terms full of jargon</li>
              <li>Emails that feel pressuring, urgent, or manipulative</li>
              <li>Anything where you want a calm second opinion</li>
            </ul>

            <h2>Tip: copy + paste beats forwarding</h2>
            <p>Some providers block scam emails before they reach us. If that happens, copy and paste the text into a new email (or attach the email as <code>.eml</code>) and we can still help.</p>

            <p style="margin-top:24px;">— Plainfully</p>
        ';
    }

    private function tplUnderusePrompt(): string
    {
        return '
            <h1>You’ve still got free clarity waiting</h1>
            <p>If you’ve got something sitting in your inbox that you’re unsure about — send it over when you’re ready.</p>
            <p><strong>Reminder:</strong> Plainfully starts with a quiet safety check, then explains what it means in plain English.</p>

            <p class="muted" style="margin-top:24px;">No rush. Just here when you need it.</p>
            <p>— Plainfully</p>
        ';
    }

    private function tplFeedbackDay20(int $userId): string
    {
        $up   = $this->feedbackUrl($userId, 'day20', 'up');
        $meh  = $this->feedbackUrl($userId, 'day20', 'meh');
        $down = $this->feedbackUrl($userId, 'day20', 'down');

        return '
            <h1>Quick check-in</h1>
            <p>Is Plainfully making things clearer?</p>

            <p>
              <a class="btn" href="' . htmlspecialchars($up) . '">👍 Helpful</a>
              <a class="btn" href="' . htmlspecialchars($meh) . '">😐 Unsure</a>
              <a class="btn" href="' . htmlspecialchars($down) . '">👎 Not for me</a>
            </p>

            <p class="muted">One tap is enough — no forms, no faff.</p>

            <p style="margin-top:24px;">— Plainfully</p>
        ';
    }

    private function tplSingleDayFollowup(int $userId): string
    {
        $up   = $this->feedbackUrl($userId, 'single_day', 'up');
        $meh  = $this->feedbackUrl($userId, 'single_day', 'meh');
        $down = $this->feedbackUrl($userId, 'single_day', 'down');

        $base = rtrim((string) getenv('APP_URL'), '/');
        if ($base === '') { $base = 'https://plainfully.com'; }
        $upgrade = $base . '/upgrade?offer=intro99p';

        return '
            <h1>How did that feel?</h1>
            <p>Just checking in — did Plainfully help you feel more confident about what you were reading?</p>

            <p>
              <a class="btn" href="' . htmlspecialchars($up) . '">👍 Yes</a>
              <a class="btn" href="' . htmlspecialchars($meh) . '">😐 Not sure</a>
              <a class="btn" href="' . htmlspecialchars($down) . '">👎 No</a>
            </p>

            <hr>

            <p><strong>Small thank-you:</strong> if you want a bit more breathing room, you can unlock the next 27 days for <strong>99p</strong> (auto-renews every 28 days, cancel anytime).</p>
            <p><a class="btn" href="' . htmlspecialchars($upgrade) . '">Get 27 days for 99p</a></p>

            <p class="muted">If you’d rather stay free, no worries — Plainfully still works the same when you need it.</p>
            <p style="margin-top:24px;">— Plainfully</p>
        ';
    }

    private function tplDirectDebitCheckin(int $userId): string
    {
        $up   = $this->feedbackUrl($userId, 'dd_checkin', 'up');
        $meh  = $this->feedbackUrl($userId, 'dd_checkin', 'meh');
        $down = $this->feedbackUrl($userId, 'dd_checkin', 'down');

        return '
            <h1>How’s Plainfully going so far?</h1>
            <p>Just a calm check-in. Is it making life feel a bit clearer day-to-day?</p>

            <p>
              <a class="btn" href="' . htmlspecialchars($up) . '">👍 Yes</a>
              <a class="btn" href="' . htmlspecialchars($meh) . '">😐 Sometimes</a>
              <a class="btn" href="' . htmlspecialchars($down) . '">👎 Not really</a>
            </p>

            <p class="muted">If something feels off, tell us — the goal is to make your life easier.</p>
            <p style="margin-top:24px;">— Plainfully</p>
        ';
    }

    private function tplMonthlyReview(int $userId): string
    {
        $up   = $this->feedbackUrl($userId, 'monthly_review', 'up');
        $meh  = $this->feedbackUrl($userId, 'monthly_review', 'meh');
        $down = $this->feedbackUrl($userId, 'monthly_review', 'down');

        return '
            <h1>Clarification Engine Review</h1>
            <p>Quick monthly check-in: is Plainfully still doing the job for you?</p>

            <p>
              <a class="btn" href="' . htmlspecialchars($up) . '">👍 Yep</a>
              <a class="btn" href="' . htmlspecialchars($meh) . '">😐 Mixed</a>
              <a class="btn" href="' . htmlspecialchars($down) . '">👎 Needs work</a>
            </p>

            <p class="muted">One tap helps us keep improving the experience.</p>
            <p style="margin-top:24px;">— Plainfully</p>
        ';
    }

    // -----------------------------
    // Presentation helpers
    // -----------------------------

    private function wrapEmail(string $innerHtml): string
    {
        return '
        <!doctype html>
        <html>
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>Plainfully</title>
        </head>
        <body style="margin:0;padding:0;background:#F7F9FA;font-family:Arial,Helvetica,sans-serif;color:#111827;">
          <div style="max-width:640px;margin:0 auto;padding:24px;">
            <div style="background:#ffffff;border:1px solid #E5E7EB;border-radius:12px;padding:24px;">
              ' . $this->emailCss() . '
              ' . $innerHtml . '
            </div>
            <div style="padding:12px 6px;color:#6B7280;font-size:12px;line-height:1.4;">
              <p style="margin:0;">You’re receiving this because you used Plainfully.</p>
            </div>
          </div>
        </body>
        </html>
        ';
    }

    private function emailCss(): string
    {
        return '
          <style>
            h1 { font-size:20px; margin:0 0 12px 0; }
            h2 { font-size:16px; margin:18px 0 8px 0; }
            p, li { font-size:14px; line-height:1.6; }
            .muted { color:#6B7280; }
            hr { border:none; border-top:1px solid #E5E7EB; margin:18px 0; }
            .btn {
              display:inline-block;
              padding:10px 12px;
              border-radius:10px;
              border:1px solid #E5E7EB;
              text-decoration:none;
              color:#111827;
              background:#ffffff;
              margin-right:8px;
              margin-bottom:8px;
              font-size:14px;
            }
            code { background:#F3F4F6; padding:2px 6px; border-radius:6px; }
          </style>
        ';
    }

    private function toText(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?: $text;
        return trim($text);
    }
}
