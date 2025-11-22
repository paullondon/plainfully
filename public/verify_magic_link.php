<?php declare(strict_types=1);

session_start();

require __DIR__ . '/../app/support/db.php';

function pf_redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/**
 * Centralised login: set the logged-in user ID in session.
 * Later you can extend this with audit logging etc.
 */
function pf_login_user(int $userId): void
{
    // Regenerate session ID to avoid fixation
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

/**
 * Normalise token from URL.
 */
function pf_normalise_token(?string $token): ?string
{
    $token = $token ?? '';
    $token = trim($token);
    if ($token === '' || !ctype_xdigit($token) || strlen($token) !== 64) {
        return null;
    }
    return $token;
}

$rawToken = pf_normalise_token($_GET['token'] ?? null);

if ($rawToken === null) {
    // Invalid token shape = generic error
    $_SESSION['magic_link_error'] = 'That link is invalid or has expired. Please request a new one.';
    pf_redirect('/login.php');
}

$tokenHash = hash('sha256', $rawToken);

try {
    $pdo = pf_db();

    // Look up token with conditions:
    // - matching hash
    // - not expired
    // - not used yet
    $stmt = $pdo->prepare(
        'SELECT m.id, m.user_id
         FROM magic_login_tokens m
         WHERE m.token_hash = :token_hash
           AND m.expires_at > NOW()
           AND m.used_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => $tokenHash]);
    $tokenRow = $stmt->fetch();

    if (!$tokenRow) {
        $_SESSION['magic_link_error'] = 'That link is invalid or has expired. Please request a new one.';
        pf_redirect('/login.php');
    }

    $tokenId = (int)$tokenRow['id'];
    $userId  = (int)$tokenRow['user_id'];

    // Mark token as used and log user in atomically
    $pdo->beginTransaction();

    $update = $pdo->prepare(
        'UPDATE magic_login_tokens
         SET used_at = NOW()
         WHERE id = :id AND used_at IS NULL'
    );
    $update->execute([':id' => $tokenId]);

    if ($update->rowCount() !== 1) {
        // Race condition: token already used by another request
        $pdo->rollBack();
        $_SESSION['magic_link_error'] = 'That link has already been used. Please request a new one.';
        pf_redirect('/login.php');
    }

    $pdo->commit();

    pf_login_user($userId);

    // Redirect to a placeholder "dashboard" for now
    pf_redirect('/index.php');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log $e in real life
    $_SESSION['magic_link_error'] = 'We could not sign you in. Please try again.';
    pf_redirect('/login.php');
}
