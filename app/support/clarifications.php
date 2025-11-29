<?php declare(strict_types=1);

/**
 * Get a PDO connection for Plainfully.
 * Adjust DSN / env variable names to match how you already store them.
 */

function plainfully_pdo(): \PDO
{
    static $pdo = null;

    if ($pdo instanceof \PDO) {
        return $pdo;
    }

    if (function_exists('pf_db')) {
        $pdo = pf_db();   // <- uses existing config from app/support/db.php
        return $pdo;
    }

    // Fallback only if pf_db() doesn't exist (shouldn't normally happen as if it does the site will be fucked on login)
    $dsn      = getenv('plainfully_db_dsn')      ?: 'mysql:host=localhost;dbname=live_plainfully;charset=utf8mb4';
    $user     = getenv('plainfully_db_user')     ?: 'plainfully';
    $password = getenv('plainfully_db_password') ?: '';

    try {
        $pdo = new \PDO(
            $dsn,
            $user,
            $password,
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (\PDOException $e) {
        // on error we will let the global exception handler show this nicely... although again site>fucked.
        throw new \RuntimeException('Plainfully DB connection failed: ' . $e->getMessage(), 0, $e);
    }

    return $pdo;
}


/**
 * Simple CSRF helpers (local to Plainfully; not using any external libs).
 */
function plainfully_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['plainfully_csrf'])) {
        $_SESSION['plainfully_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['plainfully_csrf'];
}

function plainfully_verify_csrf_token(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $stored = $_SESSION['plainfully_csrf'] ?? '';

    if ($stored === '' || $token === null) {
        return false;
    }

    return hash_equals($stored, $token);
}

/**
 * Encryption helper for consultation text.
 * Uses libsodium secretbox (symmetric encryption).
 *
 * Set an env var PLAINFULLY_SECRET_KEY as a 32-byte base64 string.
 */
function plainfully_encrypt(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }

    // If libsodium isn't available, log and store plaintext (DEV-safe behaviour).
    if (!function_exists('sodium_crypto_secretbox')) {
        error_log('[Plainfully] WARN: libsodium not available, storing text unencrypted.');
        return $plaintext;
    }

    // env is loaded via putenv(), so use getenv()
    $keyBase64 = getenv('plainfully_secret_key') ?: ''; //secret key is loaded here
    $key       = $keyBase64 !== '' ? base64_decode($keyBase64, true) : null;

    if ($key === false || $key === null || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        // Misconfigured key – log and fall back to plaintext instead of throwing.
        error_log('[Plainfully] WARN: invalid plainfully_secret_key, storing text unencrypted.');
        return $plaintext;
    }

    $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

    return base64_encode($nonce . $cipher);
}

function plainfully_decrypt(string $ciphertext): string
{
    if ($ciphertext === '') {
        return '';
    }

    // If sodium not available or key bad, just return what we got. (Crap if this happens, as the users data is gone...)
    if (!function_exists('sodium_crypto_secretbox_open')) {
        return $ciphertext;
    }

    $keyBase64 = getenv('plainfully_secret_key') ?: '';
    $key       = $keyBase64 !== '' ? base64_decode($keyBase64, true) : null;

    if ($key === false || $key === null || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        return $ciphertext;
    }

    $raw = base64_decode($ciphertext, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return $ciphertext;
    }

    $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $boxed  = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    $plain = sodium_crypto_secretbox_open($boxed, $nonce, $key);
    if ($plain === false) {
        return $ciphertext;
    }

    return $plain;
}
function plainfully_current_user_id(): ?int
{
    // If your core app already exposes this, use it.
    if (function_exists('pf_current_user_id')) {
        return pf_current_user_id();
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $id = $_SESSION['user_id'] ?? null;
    return is_numeric($id) ? (int)$id : null;
}

<?php
// ... existing functions above ...

/**
 * Find a clarification for a given user.
 * Returns associative array or null if not found / not owned.
 */
function plainfully_find_clarification_for_user(int $id, int $userId): ?array
{
    $pdo = plainfully_pdo();

    $sql = <<<SQL
SELECT
    id,
    user_id,
    tone,
    status,
    result_text,
    created_at,
    completed_at,
    expires_at
FROM clarifications
WHERE id = :id
  AND user_id = :user_id
LIMIT 1
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id'      => $id,
        ':user_id' => $userId,
    ]);

    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Handle GET /clarifications/view?id=...
 * - Ensures the clarification belongs to the current user
 * - Renders a styled result page
 * - NEVER shows original input text (only result_text + meta)
 */
function plainfully_handle_clarification_view(): void
{
    $userId = plainfully_current_user_id();
    if ($userId === null) {
        pf_redirect('/login');
    }

    $idParam = $_GET['id'] ?? null;
    if (!is_string($idParam) || !ctype_digit($idParam)) {
        // Bad id -> bounce to dashboard
        pf_redirect('/dashboard');
    }

    $clarificationId = (int)$idParam;

    $clar = plainfully_find_clarification_for_user($clarificationId, $userId);
    if ($clar === null) {
        // Not found or not owned: friendly message, no info leakage
        ob_start();
        ?>
        <section class="pf-card pf-card--narrow">
            <h1 class="pf-page-title">Clarification not found</h1>
            <p class="pf-page-subtitle">
                We couldn’t find that clarification in your history.
                It may have expired or been cancelled.
            </p>

            <div class="pf-actions pf-actions--split">
                <a href="/dashboard" class="pf-button pf-button--ghost">
                    Back to dashboard
                </a>
                <a href="/clarifications/new" class="pf-button pf-button--primary">
                    Start a new clarification
                </a>
            </div>
        </section>
        <?php
        $inner = ob_get_clean();
        pf_render_shell('Clarification not found', $inner);
        return;
    }

    // Decide if this is considered "draft" / cancellable vs completed
    $status = $clar['status'] ?? 'completed';
    $isCompleted = ($status === 'completed');
    $isCancellable = in_array($status, ['draft', 'in_progress'], true);

    // Render dedicated view template
    $pageTitle = 'Your clarification result';

    // Make $clar, $isCompleted, $isCancellable, $pageTitle available to the view
    ob_start();
    require dirname(__DIR__) . '/views/clarifications/view.php';
    $inner = ob_get_clean();

    pf_render_shell($pageTitle, $inner);
}

/**
 * Handle POST /clarifications/cancel
 * - Only allows cancelling DRAFT / IN_PROGRESS clarifications
 * - Physically deletes clarifications + details + uploads
 * - Completed clarifications are never deleted here
 */
function plainfully_handle_clarification_cancel(): void
{
    // CSRF check (only on POST; function is a no-op for GET)
    pf_verify_csrf_or_abort();

    $userId = plainfully_current_user_id();
    if ($userId === null) {
        pf_redirect('/login');
    }

    $idParam = $_POST['clarification_id'] ?? null;
    if (!is_string($idParam) || !ctype_digit($idParam)) {
        pf_redirect('/dashboard');
    }

    $clarificationId = (int)$idParam;

    $pdo = plainfully_pdo();

    // Load the clarification row first
    $clar = plainfully_find_clarification_for_user($clarificationId, $userId);

    if ($clar === null) {
        // Nothing to cancel -> just go home
        pf_redirect('/dashboard');
    }

    $status = $clar['status'] ?? 'completed';

    // Completed clarifications: protect from deletion
    if ($status === 'completed') {
        // Optionally flash a message later; for now just redirect
        pf_redirect('/clarifications/view?id=' . urlencode((string)$clarificationId));
    }

    // Only allow explicit draft/in_progress to be cancelled
    if (!in_array($status, ['draft', 'in_progress'], true)) {
        pf_redirect('/dashboard');
    }

    // Hard-delete draft consultation + related rows
    try {
        $pdo->beginTransaction();

        // Delete uploads first (if used)
        $stmt = $pdo->prepare('DELETE FROM clarification_uploads WHERE clarification_id = :id');
        $stmt->execute([':id' => $clarificationId]);

        // Delete details (encrypted prompt, etc.)
        $stmt = $pdo->prepare('DELETE FROM clarification_details WHERE clarification_id = :id');
        $stmt->execute([':id' => $clarificationId]);

        // Delete main clarification row
        $stmt = $pdo->prepare('DELETE FROM clarifications WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            ':id'      => $clarificationId,
            ':user_id' => $userId,
        ]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Plainfully] Failed to cancel clarification: ' . $e->getMessage());
        // Don’t leak details, just return user to dashboard
    }

    // After cancellation, the consultation is invisible to the user
    pf_redirect('/dashboard');
}
