<?php declare(strict_types=1);

session_start();

require __DIR__ . '/../app/support/db.php';

/**
 * Small helper: generic redirect + exit so we don't repeat ourselves.
 */
function pf_redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/**
 * Basic server-side email validation.
 * We still treat errors generically to avoid leaking if a user exists.
 */
function pf_normalise_email(string $email): ?string
{
    $email = trim(mb_strtolower($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return $email;
}

/**
 * Generate a secure random token string for the magic link.
 * We store only the SHA-256 hash in the database.
 */
function pf_generate_magic_token(): string
{
    return bin2hex(random_bytes(32)); // 64-char hex
}

/**
 * Send the magic link email.
 * Replace with your preferred mailer / SMTP integration later.
 */
function pf_send_magic_link_email(string $toEmail, string $link): bool
{
    $config = require __DIR__ . '/../config/app.php';
    $mail   = $config['mail'];

    $subject = 'Your Plainfully sign-in link';
    $body    = "Hi,\n\n"
             . "Use the link below to sign in to Plainfully. "
             . "It will expire shortly and can only be used once.\n\n"
             . $link . "\n\n"
             . "If you did not request this, you can ignore this email.\n";

    $headers = [];
    $headers[] = 'From: ' . sprintf('"%s" <%s>',
        $mail['from_name'],
        $mail['from_email']
    );
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';

    return @mail($toEmail, $subject, $body, implode("\r\n", $headers));
}

// ===== Main handler logic =====

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pf_redirect('/login.php');
}

$config = require __DIR__ . '/../config/app.php';
$baseUrl = rtrim($config['app']['base_url'], '/');
$ttlMinutes = (int)($config['auth']['magic_link_ttl_minutes'] ?? 30);

$email = $_POST['email'] ?? '';
$email = pf_normalise_email($email);

// We always show a generic response, whether email is valid or not, for privacy.
$genericMessage = 'If that email is registered, a sign-in link will arrive shortly.';

if ($email === null) {
    $_SESSION['magic_link_error'] = 'Please enter a valid email address.';
    pf_redirect('/login.php');
}

try {
    $pdo = pf_db();
    $pdo->beginTransaction();

    // 1) Find or create the user
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        $stmt = $pdo->prepare('INSERT INTO users (email) VALUES (:email)');
        $stmt->execute([':email' => $email]);
        $userId = (int)$pdo->lastInsertId();
    } else {
        $userId = (int)$user['id'];
    }

    // 2) Create the token
    $rawToken = pf_generate_magic_token();
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = (new DateTimeImmutable("+{$ttlMinutes} minutes"))->format('Y-m-d H:i:s');

    $ip    = $_SERVER['REMOTE_ADDR']    ?? null;
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $insert = $pdo->prepare(
        'INSERT INTO magic_login_tokens
            (user_id, token_hash, expires_at, created_ip, created_agent)
         VALUES
            (:user_id, :token_hash, :expires_at, :ip, :agent)'
    );
    $insert->execute([
        ':user_id'    => $userId,
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
        ':ip'         => $ip,
        ':agent'      => mb_substr((string)$agent, 0, 255),
    ]);

    $pdo->commit();

    // 3) Build the public link containing the raw token (not the hash)
    $link = $baseUrl . '/verify_magic_link.php?token=' . urlencode($rawToken);

    // 4) Fire email (errors are handled generically)
    if (!pf_send_magic_link_email($email, $link)) {
        // We don't reveal if user exists or not; generic error
        $_SESSION['magic_link_error'] = 'Something went wrong sending your link. Please try again in a moment.';
    } else {
        $_SESSION['magic_link_ok'] = $genericMessage;
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Log $e in a real system
    $_SESSION['magic_link_error'] = 'We had trouble processing that request. Please try again.';
}

pf_redirect('/login.php');
