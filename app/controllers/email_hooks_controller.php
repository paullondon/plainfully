<?php declare(strict_types=1);

use App\Features\Checks\CheckInput;
use App\Features\Checks\CheckEngine;
use App\Features\Checks\DummyAiClient;

// Load CheckEngine classes for this controller
require_once dirname(__DIR__) . '/features/checks/check_input.php';
require_once dirname(__DIR__) . '/features/checks/check_result.php';
require_once dirname(__DIR__) . '/features/checks/ai_client.php';
require_once dirname(__DIR__) . '/features/checks/check_engine.php';

/**
 * Dev-only inbound email hook.
 *
 * Simulates what a real email provider webhook would do:
 *  - POSTs "from", "to", "subject", "body"
 *  - We verify a shared secret
 *  - We feed it into CheckEngine with channel="email"
 *  - We send a brief reply email to the sender
 *  - We return JSON with the CheckEngine result (no raw content stored)
 */
function email_inbound_dev_controller(): void
{
    global $config; // base URL likely lives here

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
    $rawContent = $subject !== ''
        ? $subject . "\n\n" . $body
        : $body;

    $pdo        = pf_db();
    $aiClient   = new DummyAiClient(); // swap later for real AI client
    $checkEngine = new CheckEngine($pdo, $aiClient);

    // Treat email "from" as the user’s email
    $input = new CheckInput(
        'email',        // channel
        $from,          // source_identifier
        'text/plain',   // content type
        $rawContent,    // raw content (never stored)
        $from,          // email
        null,           // phone
        null            // provider_user_id
    );

    // For now, all email checks are free tier
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

        // If your pf_mail helper supports HTML, you can build a nicer HTML version later.
        $emailSent = false;
        try {
            if (function_exists('pf_mail')) {
                // This assumes pf_mail($to, $subject, $bodyText) or similar.
                // If your helper signature is different, we’ll adjust once it errors.
                pf_mail($from, $emailSubject, $textBody);
                $emailSent = true;
            }
        } catch (Throwable $mailError) {
            // Swallow mail errors for the webhook – we still return 200 with email_sent=false
            $emailSent = false;
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'status'         => 'ok',
            'check_id'       => $result->checkId,
            'short_verdict'  => $result->shortVerdict,
            'is_scam'        => $result->isScam,
            'is_paid'        => $result->isPaid,
            'input_capsule'  => $result->inputCapsule,
            'upsell_flags'   => $result->upsellFlags,
            'view_url'       => $viewUrl,
            'email_sent'     => $emailSent,
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
