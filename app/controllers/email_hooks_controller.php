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
 * Extract a "root" domain from a host, e.g.:
 *  - mail.example.com      -> example.com
 *  - example.com           -> example.com
 *  - mycorp.co.uk          -> mycorp.co.uk   (naive but fine for heuristics)
 */
function plainfully_root_domain_from_host(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/:\d+$/', '', $host); // strip :port

    $parts = explode('.', $host);
    $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));

    if (count($parts) <= 2) {
        return implode('.', $parts);
    }

    $ukLikeTlds = ['co.uk', 'org.uk', 'gov.uk', 'ac.uk'];

    $lastTwo   = implode('.', array_slice($parts, -2));
    $lastThree = implode('.', array_slice($parts, -3));

    if (in_array($lastTwo, $ukLikeTlds, true) && count($parts) >= 3) {
        return $lastThree; // e.g. mycorp.co.uk
    }

    return $lastTwo; // e.g. example.com
}

/**
 * Extract sender root domain from an email address.
 */
function plainfully_root_domain_from_email(?string $email): ?string
{
    if (!$email || !str_contains($email, '@')) {
        return null;
    }

    [$local, $domain] = explode('@', $email, 2);
    $domain = trim($domain);

    if ($domain === '') {
        return null;
    }

    return plainfully_root_domain_from_host($domain);
}

/**
 * Turn subject + body (plain text OR HTML) into safe, visible text.
 * - Strips HTML tags
 * - Marks hyperlinks as "[link]" or "[link – potentially risky]"
 *   if the href domain does not match the sender domain.
 */
function plainfully_normalise_email_text(string $subject, string $body, ?string $fromEmail = null): string
{
    // Combine subject + body first
    $full = $subject !== '' ? ($subject . "\n\n" . $body) : $body;

    $senderRoot = plainfully_root_domain_from_email($fromEmail);

    // Fast path: if there's no obvious HTML, just return trimmed text
    if (stripos($full, '<a ') === false &&
        stripos($full, '<html') === false &&
        stripos($full, '<body') === false) {
        return trim($full);
    }

    // 1) Replace <a href="...">text</a> with "text [link]" or "text [link – potentially risky]"
    $full = preg_replace_callback(
        '/<a\b[^>]*>(.*?)<\/a>/is',
        static function (array $matches) use ($senderRoot): string {
            $anchorHtml  = $matches[0] ?? '';
            $anchorInner = $matches[1] ?? '';

            // Visible link text (strip nested tags)
            $anchorText = trim(strip_tags($anchorInner));
            if ($anchorText === '') {
                $anchorText = 'link';
            }

            $suffix = ' [link]';

            if ($senderRoot !== null) {
                $href = null;

                if (preg_match('/href\s*=\s*"([^"]*)"/i', $anchorHtml, $m)) {
                    $href = $m[1];
                } elseif (preg_match("/href\s*=\s*'([^']*)'/i", $anchorHtml, $m)) {
                    $href = $m[1];
                }

                if ($href !== null && $href !== '') {
                    $host = parse_url($href, PHP_URL_HOST);

                    if (is_string($host) && $host !== '') {
                        $linkRoot = plainfully_root_domain_from_host($host);

                        if ($linkRoot !== '' && strcasecmp($linkRoot, $senderRoot) !== 0) {
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
