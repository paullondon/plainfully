<?php declare(strict_types=1);

use App\Features\Checks\CheckInput;
use App\Features\Checks\CheckEngine;
use App\Features\Checks\DummyAiClient;

// Load CheckEngine feature classes
require_once dirname(__DIR__) . '/features/checks/check_input.php';
require_once dirname(__DIR__) . '/features/checks/check_result.php';
require_once dirname(__DIR__) . '/features/checks/ai_client.php';
require_once dirname(__DIR__) . '/features/checks/check_engine.php';

/**
 * Get the domain part from an email address (after the @).
 * Used only for lightweight link-risk hints. We never store full URLs.
 */
function plainfully_email_sender_domain(?string $email): ?string
{
    if (!$email || strpos($email, '@') === false) {
        return null;
    }

    $domain = trim(substr(strrchr($email, '@'), 1) ?: '');
    if ($domain === '') {
        return null;
    }

    // Normalise and strip an optional :port
    $domain = strtolower($domain);
    $domain = preg_replace('/:\d+$/', '', $domain);

    return $domain ?: null;
}

/**
 * Turn subject + body (plain text OR HTML) into safe, visible text.
 *
 * - Strips HTML tags.
 * - For <a href="...">text</a>:
 *      If link host loosely contains sender domain (or vice versa) -> "text [link]"
 *      Otherwise                                                   -> "text [link – potentially risky]"
 * - Does NOT store URLs anywhere; href is only inspected in-memory.
 */
function plainfully_normalise_email_text(string $subject, string $body, ?string $fromEmail = null): string
{
    // Combine subject + body first
    $full = $subject !== '' ? ($subject . "\n\n" . $body) : $body;

    $senderDomain = plainfully_email_sender_domain($fromEmail);

    // Fast path: if there's no obvious HTML, just return trimmed text
    if (stripos($full, '<a ') === false &&
        stripos($full, '<html') === false &&
        stripos($full, '<body') === false) {
        return trim($full);
    }

    // 1) Replace <a href="...">text</a> with "text [link]" or "text [link – potentially risky]"
    $full = preg_replace_callback(
        '/<a\b[^>]*>(.*?)<\/a>/is',
        static function (array $matches) use ($senderDomain): string {
            $anchorHtml  = $matches[0] ?? '';
            $anchorInner = $matches[1] ?? '';

            // Visible link text (strip nested tags)
            $anchorText = trim(strip_tags($anchorInner));
            if ($anchorText === '') {
                $anchorText = 'link';
            }

            // Default: neutral link
            $suffix = ' [link]';

            if ($senderDomain !== null) {
                $href = null;

                if (preg_match('/href\s*=\s*"([^"]*)"/i', $anchorHtml, $m)) {
                    $href = $m[1];
                } elseif (preg_match("/href\s*=\s*'([^']*)'/i", $anchorHtml, $m)) {
                    $href = $m[1];
                }

                if ($href !== null && $href !== '') {
                    $host = parse_url($href, PHP_URL_HOST);

                    if (is_string($host) && $host !== '') {
                        $host = strtolower($host);

                        // We never store $host – we only use it here to decide suffix.
                        $isSimilar =
                            stripos($host, $senderDomain) !== false ||
                            stripos($senderDomain, $host) !== false;

                        if (!$isSimilar) {
                            $suffix = ' [link – potentially risky]';
                        }
                    }
                }
            }

            return $anchorText . $suffix;
        },
        $full
    );

    // 2) Strip remaining tags
    $full = strip_tags($full);

    // 3) Decode HTML entities
    $full = html_entity_decode($full, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // 4) Normalise whitespace
    $full = str_replace(["\r\n", "\r"], "\n", $full);
    $full = preg_replace('/[ \t]+/u', ' ', $full);
    $full = preg_replace("/\n{3,}/u", "\n\n", $full);

    return trim($full);
}

/**
 * Dev-only inbound email hook.
 *
 * Simulates an email provider webhook:
 *  - POSTs "from", "to", "subject", "body"
 *  - We verify a shared secret
 *  - We feed it into CheckEngine with channel="email"
 *  - We send a brief reply email to the sender
 *  - We return JSON with the CheckEngine result (no raw content stored)
 */
function email_inbound_dev_controller(): void
{
    global $config;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Method not allowed. Use POST.']);
        return;
    }

    $tokenHeader = $_SERVER['HTTP_X_PLAINFULLY_TOKEN'] ?? '';
    $expected    = getenv('EMAIL_HOOK_TOKEN') ?: '';

    if ($expected === '' || !hash_equals($expected, $tokenHeader)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unauthorised email hook call.']);
        return;
    }

    $from    = trim($_POST['from']    ?? '');
    $to      = trim($_POST['to']      ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body']    ?? '');

    if ($from === '' || $body === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Fields "from" and "body" are required.']);
        return;
    }

    // Build the raw content (in-memory only; not stored)
    // Handles both plain text and HTML (anchors -> "[link]" / "[link – potentially risky]")
    $rawContent = plainfully_normalise_email_text($subject, $body, $from);

    $pdo        = pf_db();
    $aiClient   = new DummyAiClient();
    $checkEngine = new CheckEngine($pdo, $aiClient);

    $input = new CheckInput(
        'email',        // channel
        $from,          // source_identifier
        'text/plain',   // content type
        $rawContent,    // safe content
        $from,          // email
        null,           // phone
        null            // provider_user_id
    );

    $isPaid = false;

    try {
        $result = $checkEngine->run($input, $isPaid);

        // Build a view URL for the user
        $baseUrl = '';
        if (isset($config['app']['url']) && is_string($config['app']['url'])) {
            $baseUrl = rtrim($config['app']['url'], '/');
        } else {
            $baseUrl = 'https://plainfully.com';
        }

        $viewUrl = $baseUrl . '/clarifications/view?id=' . $result->checkId;

        // Compose reply email (NO raw content, only capsule + verdict)
        $emailSubject = '[Plainfully] Scam check result';
        $verdictLine  = $result->isScam
            ? 'Our system believes this message is LIKELY A SCAM.'
            : 'Our system did not detect obvious scam indicators.';

        $textBodyLines = [
            "Hi,",
            "",
            "You forwarded a message to Plainfully for a quick scam/clarity check.",
            "",
            $verdictLine,
            "",
            "Short summary of what we analysed (not the full text):",
            $result->inputCapsule,
            "",
            "To see the full breakdown and guidance, open:",
            $viewUrl,
            "",
            "Plainfully never stores the full message content – only safe summaries.",
            "",
            "— Plainfully",
        ];
        $textBody = implode("\n", $textBodyLines);

        // Email sending with pf_mail() if it exists, otherwise native mail()
        $emailSent        = false;
        $mailErrorMessage = null;

        try {
            if (function_exists('pf_mail')) {
                $res       = pf_mail($from, $emailSubject, $textBody);
                $emailSent = ($res === null) ? true : (bool) $res;
            } else {
                $fromAddress = 'no-reply@plainfully.com';

                $headers  = 'From: Plainfully Scam Check <' . $fromAddress . ">\r\n";
                $headers .= 'Reply-To: ' . $fromAddress . "\r\n";
                $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
                $headers .= "MIME-Version: 1.0\r\n";

                $ok = mail($from, $emailSubject, $textBody, $headers);

                if (!$ok) {
                    throw new RuntimeException('mail() returned false; email not sent.');
                }

                $emailSent = true;
            }
        } catch (Throwable $mailError) {
            $emailSent        = false;
            $mailErrorMessage = $mailError->getMessage();
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');

        $payload = [
            'status'         => 'ok',
            'check_id'       => $result->checkId,
            'short_verdict'  => $result->shortVerdict,
            'is_scam'        => $result->isScam,
            'is_paid'        => $result->isPaid,
            'input_capsule'  => $result->inputCapsule,
            'upsell_flags'   => $result->upsellFlags,
            'view_url'       => $viewUrl,
            'email_sent'     => $emailSent,
        ];

        // Expose mail error only in non-live envs
        $appEnv = getenv('APP_ENV') ?: 'local';
        if (strtolower($appEnv) !== 'live' && strtolower($appEnv) !== 'production') {
            if ($mailErrorMessage !== null) {
                $payload['mail_error'] = $mailErrorMessage;
            }
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $t) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Internal error running CheckEngine.',
            'code'  => 'checkengine_failure',
        ]);
    }
}
