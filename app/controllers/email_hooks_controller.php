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

/**
 * handle_email_inbound()
 *
 * Accepts POST JSON forwarded from the IMAP bridge.
 * Always returns JSON. Never calls view helpers.
 */

function handle_email_inbound(): void
{
    header('Content-Type: application/json');

    try {
        $json = file_get_contents('php://input');
        $payload = json_decode($json, true);

        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid or missing JSON']);
            return;
        }

        // TODO: swap in real processing later
        // For now we simply log the inbound payload for debugging.
        error_log('Inbound email received: ' . $json);

        echo json_encode(['ok' => true]);
    }
    catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok'    => false,
            'error' => $e->getMessage()
        ]);
    }
}


function email_inbound_dev_controller(): void
{
    global $config;

    // 1) Method + auth token
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
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

    // 2) Basic fields
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

    // 3) Normalise email text (HTML → safe text, mark risky links)
    $rawContent = plainfully_normalise_email_text($subject, $body, $from);

    // 4) Decide mode based on TO address
    $toLower      = strtolower($to);
    $mode         = 'generic';
    $checkChannel = 'email';
    $emailChannel = 'noreply';

    if ($toLower !== '') {
        if (str_contains($toLower, 'scamcheck@')) {
            $mode         = 'scamcheck';
            $checkChannel = 'email-scamcheck';
            $emailChannel = 'scamcheck';
        } elseif (str_contains($toLower, 'clarify@')) {
            $mode         = 'clarify';
            $checkChannel = 'email-clarify';
            $emailChannel = 'clarify';
        }
    }

    // 5) Run through CheckEngine (CORE brain)
    $pdo       = pf_db();
    $aiClient  = new DummyAiClient();   // swap later for real AI client
    $engine    = new CheckEngine($pdo, $aiClient);

    $input = new CheckInput(
        $checkChannel,   // channel: email-scamcheck / email-clarify / email
        $from,           // source_identifier (we use sender address)
        'text/plain',    // content type
        $rawContent,     // safe, normalised content
        $from,           // email
        null,            // phone
        null             // provider_user_id
    );

    $isPaid = false;

    try {
        $result = $engine->run($input, $isPaid);

        // 6) Build view URL
        $baseUrl = rtrim($config['app']['base_url'] ?? 'https://plainfully.com', '/');
        $viewUrl = $baseUrl . '/clarifications/view?id=' . $result->id;

        // 7) Compose outbound email (different flavour per mode)
        if ($mode === 'scamcheck') {
            $outSubject = 'Plainfully ScamCheck result';
            $intro      = 'You forwarded a message to Plainfully ScamCheck. Here is our quick verdict:';
        } elseif ($mode === 'clarify') {
            $outSubject = 'Plainfully clarification result';
            $intro      = 'You emailed Plainfully for clarification. Here is your summary:';
        } else {
            $outSubject = 'Plainfully check result';
            $intro      = 'Here is the summary of the text you sent to Plainfully:';
        }

        $htmlBody = '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
                  . '<p><strong>Verdict:</strong> '
                  . htmlspecialchars($result->shortVerdict, ENT_QUOTES, 'UTF-8') . '</p>'
                  . '<p><strong>Summary:</strong><br>'
                  . nl2br(htmlspecialchars($result->inputCapsule, ENT_QUOTES, 'UTF-8')) . '</p>'
                  . '<p>You can view this check on Plainfully here:<br>'
                  . '<a href="' . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '">'
                  . htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') . '</a></p>';

        $textBody = $intro . "\n\n"
                  . 'Verdict: ' . $result->shortVerdict . "\n\n"
                  . "Summary:\n" . $result->inputCapsule . "\n\n"
                  . "View this check on Plainfully:\n" . $viewUrl . "\n";

        $emailSent = false;
        $mailError = null;

        if (function_exists('pf_send_email')) {
            [$emailSent, $mailError] = pf_send_email(
                $from,
                $outSubject,
                $htmlBody,
                $emailChannel,   // 'scamcheck' / 'clarify' / 'noreply'
                $textBody
            );
        } else {
            $mailError = 'pf_send_email helper not defined.';
        }

        // 8) JSON response for your PowerShell tests
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'         => 'ok',
            'check_id'       => $result->id,
            'short_verdict'  => $result->shortVerdict,
            'is_scam'        => $result->isScam,
            'is_paid'        => $result->isPaid,
            'input_capsule'  => $result->inputCapsule,
            'upsell_flags'   => $result->upsellFlags,
            'view_url'       => $viewUrl,
            'email_sent'     => $emailSent,
            'mail_error'     => $mailError,
            'mode'           => $mode,
        ]);
    } catch (Throwable $e) {
        error_log('email_inbound_dev_controller error: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Internal error processing email hook.',
        ]);
    }
}

/**
 * Dev-only inbound SMS hook.
 *
 * Simulates an SMS provider webhook:
 *  - POSTs "from", "body"
 *  - We verify a shared secret
 *  - We feed it into CheckEngine with channel="sms"
 *  - We DO NOT send any email – only JSON response
 */
function sms_inbound_dev_controller(): void
{
    global $config;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Method not allowed. Use POST.']);
        return;
    }

    // Separate token so you can rotate / control SMS independently
    $tokenHeader = $_SERVER['HTTP_X_PLAINFULLY_TOKEN'] ?? '';
    $expected    = getenv('SMS_HOOK_TOKEN') ?: '';

    if ($expected === '' || !hash_equals($expected, $tokenHeader)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unauthorised SMS hook call.']);
        return;
    }

    $from = trim($_POST['from'] ?? '');
    $body = trim($_POST['body'] ?? '');

    if ($from === '' || $body === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Fields "from" and "body" are required.']);
        return;
    }

    // For SMS we don’t need special HTML handling – CheckEngine’s
    // enforceInputSafety will take care of length, URLs, swear filters, etc.
    $rawContent = $body;

    $pdo        = pf_db();
    $aiClient   = new DummyAiClient();
    $checkEngine = new CheckEngine($pdo, $aiClient);

    $input = new CheckInput(
        'sms',          // channel
        $from,          // source_identifier (phone number)
        'text/plain',   // content type
        $rawContent,    // content (will be cleaned inside CheckEngine)
        null,           // email
        $from,          // phone
        null            // provider_user_id
    );

    $isPaid = false;

    try {
        $result = $checkEngine->run($input, $isPaid);

        // Build a simple SMS reply template (for future SMS provider integration)
        if ($result->isScam) {
            $smsReply = 'Plainfully: This text looks like a scam. Do not click links or share personal or payment details. If in doubt, contact the company using a trusted phone number or website.';
        } else {
            $smsReply = 'Plainfully: We did not find obvious scam signs in this text, but still be cautious with links and any requests for personal or payment details.';
        }

        // Build a view URL for the user (same pattern as email)
        $baseUrl = '';
        if (isset($config['app']['url']) && is_string($config['app']['url'])) {
            $baseUrl = rtrim($config['app']['url'], '/');
        } else {
            $baseUrl = 'https://plainfully.com';
        }

        $viewUrl = $baseUrl . '/clarifications/view?id=' . $result->checkId;

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        
        echo json_encode([
            'status'              => 'ok',
            'check_id'            => $result->checkId,
            'short_verdict'       => $result->shortVerdict,
            'is_scam'             => $result->isScam,
            'is_paid'             => $result->isPaid,
            'input_capsule'       => $result->inputCapsule,
            'upsell_flags'        => $result->upsellFlags,
            'view_url'            => $viewUrl,
            'sms_reply_template'  => $smsReply,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    } catch (Throwable $t) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => 'Internal error running CheckEngine.',
            'code'  => 'checkengine_failure',
        ]);
    }
}

