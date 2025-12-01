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





/**
 * Temporary stub "AI" for Plainfully results.
 *
 * This generates:
 * - A TL;DR section with a simple risk level
 * - A Full Report section with placeholder structure
 *
 * Later, the real AI output MUST keep the same
 * "TL;DR ... FULL REPORT" structure so we can
 * reliably split it for display.
 */
function plainfully_stub_model_response(string $input): string
{
    // --- Very simple risk heuristics for now (MVP only) ---
    $lower = mb_strtolower($input);

    $riskLevel = 'low';
    $riskIcon  = '🔵 Low risk';

    if (
        str_contains($lower, 'final notice')
        || str_contains($lower, 'final warning')
        || str_contains($lower, 'court')
        || str_contains($lower, 'bailiff')
        || str_contains($lower, 'termination')
        || str_contains($lower, 'disconnected')
        || str_contains($lower, 'eviction')
    ) {
        $riskLevel = 'high';
        $riskIcon  = '🔴 High risk';
    } elseif (
        str_contains($lower, 'reminder')
        || str_contains($lower, 'overdue')
        || str_contains($lower, 'action required')
        || str_contains($lower, 'please respond')
    ) {
        $riskLevel = 'medium';
        $riskIcon  = '🟠 Medium risk';
    }

    // --- TL;DR block (fast, anxiety-friendly) ---
    $tldr = "TL;DR\n"
          . "{$riskIcon}\n"
          . "This message has been summarised for you. "
          . "Read the details below if you need the full breakdown.\n\n";

    // --- Full Report placeholder (structure only for now) ---
    $full  = "FULL REPORT\n";
    $full .= "-------------------------------------\n";
    $full .= "Plain explanation:\n";
    $full .= "This is a placeholder plain-language explanation of the message you pasted. "
          . "In the live version, Plainfully will explain the meaning in simple, calm terms.\n\n";

    $full .= "Key things to know:\n";
    $full .= "- Example key point 1 about the message.\n";
    $full .= "- Example key point 2 about obligations, money, or dates.\n";
    $full .= "- Example key point 3 about what is being requested.\n\n";

    $full .= "Risks / cautions:\n";
    if ($riskLevel === 'high') {
        $full .= "This looks like a serious or urgent message. "
               . "Pay attention to deadlines, money amounts, and any warnings it contains.\n\n";
    } elseif ($riskLevel === 'medium') {
        $full .= "This message likely contains important information or a time-sensitive request "
               . "that you should review carefully.\n\n";
    } else {
        $full .= "No obvious major risks detected from the wording alone, but always check the original "
               . "document if you are unsure.\n\n";
    }

    $full .= "What people typically do in this situation:\n";
    $full .= "In similar situations, people often:\n";
    $full .= "- Re-read the message slowly to confirm what is being asked.\n";
    $full .= "- Check any dates, amounts, or deadlines mentioned.\n";
    $full .= "- Contact the sender for clarification if something is unclear.\n\n";

    $full .= "Short summary:\n";
    $full .= "This is a placeholder summary of the message. In the live version, Plainfully will give "
           . "a short recap of what the message is about and why it matters.\n";

    return $tldr . $full;
}

/**
 * Split a Plainfully result blob into:
 * - 'tldr'       => TL;DR section
 * - 'full'       => full report section
 *
 * It expects the text to contain the marker "FULL REPORT".
 * If the marker is missing, the whole text is treated as TL;DR
 * and the full report is left identical (safe fallback).
 */
function plainfully_split_result_sections(string $resultText): array
{
    $markerPos = mb_stripos($resultText, 'FULL REPORT');

    if ($markerPos === false) {
        // Safe fallback: show same text in both sections
        $clean = trim($resultText);
        return [
            'tldr' => $clean,
            'full' => $clean,
        ];
    }

    $tldr = trim(mb_substr($resultText, 0, $markerPos));
    $full = trim(mb_substr($resultText, $markerPos));

    return [
        'tldr' => $tldr,
        'full' => $full,
    ];
}

/**
 * Handle POST /clarifications/new
 * - Validates input
 * - Encrypts prompt + stub result
 * - Inserts into clarifications + clarification_details
 * - Redirects to /clarifications/view?id=...
 */
function plainfully_handle_clarification_new_post_v2(): void
{
    // CSRF protection
    pf_verify_csrf_or_abort();

    $userId = plainfully_current_user_id();
    if ($userId === null) {
        pf_redirect('/login');
    }


    $text = $_POST['text'] ?? '';
    if (!is_string($text)) {
        $text = '';
    }
    $text = trim($text);

    $errors = [];

    if ($text === '') {
        $errors[] = 'Please paste the message you want Plainfully to clarify.';
    } elseif (mb_strlen($text) > 8000) {
        $errors[] = 'That message is a bit long. Please trim it down and try again.';
    }

    if (!empty($errors)) {
        // Reuse your existing form renderer (from A2)
        render_plainfully_clarification_form(
            $errors,
            ['text' => $text]
        );
        return;
    }

    // Encrypt sensitive fields
    try {
        $promptCipher = plainfully_encrypt($text);

        $stubText     = plainfully_stub_model_response($text);
        $clarCipher   = plainfully_encrypt($stubText);

        // Model response / redacted summary left empty for now
        $modelCipher     = null;
        $summaryCipher   = null;
    } catch (\Throwable $e) {
        error_log('[Plainfully] Failed to encrypt clarification fields: ' . $e->getMessage());

        render_plainfully_clarification_form(
            ['Something went wrong securing your text. Please try again.'],
            ['text' => $text]
        );
        return;
    }

    $pdo = plainfully_pdo();

    $now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $createdAt = $now->format('Y-m-d H:i:s');
    $updatedAt = $createdAt;
    $expiresAt = $now->modify('+28 days')->format('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // Insert into clarifications (meta only)
        $stmt = $pdo->prepare(
            'INSERT INTO clarifications
                 (user_id, email_hash, source, status, tone, created_at, updated_at, expires_at)
             VALUES
                 (:user_id, :email_hash, :source, :status, :tone, :created_at, :updated_at, :expires_at)'
        );

        $stmt->execute([
            ':user_id'    => $userId,
            ':email_hash' => null,        // optional for now
            ':source'     => 'web',
            ':status'     => 'open',      // treat "open" as completed enough to show
            ':tone'       => 'notrequired',
            ':created_at' => $createdAt,
            ':updated_at' => $updatedAt,
            ':expires_at' => $expiresAt,
        ]);

        $clarificationId = (int)$pdo->lastInsertId();

        // Insert first detail row (prompt + clarified stub)
        $stmt = $pdo->prepare(
            'INSERT INTO clarification_details
                 (clarification_id,
                  role,
                  sequence_no,
                  prompt_ciphertext,
                  clarification_ciphertext,
                  model_response_ciphertext,
                  redacted_summary_ciphertext,
                  created_at,
                  updated_at,
                  expires_at)
             VALUES
                 (:clarification_id,
                  :role,
                  :sequence_no,
                  :prompt_ciphertext,
                  :clarification_ciphertext,
                  :model_response_ciphertext,
                  :redacted_summary_ciphertext,
                  :created_at,
                  :updated_at,
                  :expires_at)'
        );

        $stmt->execute([
            ':clarification_id'            => $clarificationId,
            ':role'                        => 'assistant',   // or 'system' if you prefer
            ':sequence_no'                 => 1,
            ':prompt_ciphertext'           => $promptCipher,
            ':clarification_ciphertext'    => $clarCipher,
            ':model_response_ciphertext'   => $modelCipher,
            ':redacted_summary_ciphertext' => $summaryCipher,
            ':created_at'                  => $createdAt,
            ':updated_at'                  => $updatedAt,
            ':expires_at'                  => $expiresAt,
        ]);

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Plainfully] Failed to save clarification: ' . $e->getMessage());

        render_plainfully_clarification_form(
            ['Something went wrong saving your clarification. Please try again.'],
            ['text' => $text]
        );
        return;
    }

    // All good – show the result page
    pf_redirect('/clarifications/view?id=' . urlencode((string)$clarificationId));
}















/**
 * Handle GET /clarifications/view?id=...
 * - Ensures the clarification belongs to the current user
 * - Renders a styled result page
 * - NEVER shows original input text (only result_text + meta)
 */
function plainfully_load_clarification_result_for_user(int $clarificationId, int $userId): ?array
{
    $pdo = plainfully_pdo();

    // 1) Main clarification row (ownership enforced here)
    $stmt = $pdo->prepare(
        'SELECT
             id,
             user_id,
             tone,
             status,
             source,
             created_at,
             updated_at,
             expires_at
         FROM clarifications
         WHERE id = :id
           AND user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute([
        ':id'      => $clarificationId,
        ':user_id' => $userId,
    ]);

    $clar = $stmt->fetch();
    if ($clar === false) {
        return null;
    }

    // 2) Latest detail row for this clarification
    $stmt = $pdo->prepare(
        'SELECT
             clarification_ciphertext,
             model_response_ciphertext,
             redacted_summary_ciphertext
         FROM clarification_details
         WHERE clarification_id = :id
         ORDER BY sequence_no DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([':id' => $clarificationId]);

    $detail = $stmt->fetch();
    $resultText = '';

    if ($detail !== false) {
        $cipher = $detail['clarification_ciphertext'] ?? null;

        if (empty($cipher) && !empty($detail['model_response_ciphertext'])) {
            $cipher = $detail['model_response_ciphertext'];
        }
        if (empty($cipher) && !empty($detail['redacted_summary_ciphertext'])) {
            $cipher = $detail['redacted_summary_ciphertext'];
        }

        if (!empty($cipher)) {
            try {
                $resultText = (string) plainfully_decrypt($cipher);
            } catch (Throwable $e) {
                error_log('[Plainfully] Failed to decrypt clarification result: ' . $e->getMessage());
                $resultText = '';
            }
        }
    }

    return [
        'clar'        => $clar,
        'result_text' => $resultText,
    ];
}

/**
 * Handle GET /clarifications/view?id=...
 * - Ensures the clarification belongs to the current user
 * - Loads + decrypts the result text
 * - Renders the result page (never shows original prompt)
 */
function plainfully_handle_clarification_view(): void
{
    $userId = plainfully_current_user_id();
    if ($userId === null) {
        pf_redirect('/login');
    }

    $idParam = $_GET['id'] ?? null;
    if (!is_string($idParam) || !ctype_digit($idParam)) {
        pf_redirect('/dashboard');
    }

    $clarificationId = (int)$idParam;

    // Load clarification meta + decrypted result
    $data = plainfully_load_clarification_result_for_user($clarificationId, $userId);
    if ($data === null) {
        // Not found / not owned
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

    $clar       = $data['clar'];
    $resultText = (string)($data['result_text'] ?? '');

    if ($resultText === '') {
        $resultText = '[Result text is currently empty – check encryption/decryption.]';
    }

    // Keep original blob attached as well (for any future use)
    $clar['result_text'] = $resultText;

    // --- NEW: split into TL;DR + Full Report for the template ---
    $sections       = plainfully_split_result_sections($resultText);
    $tldrText       = $sections['tldr'];
    $fullReportText = $sections['full'];

    $status    = $clar['status']     ?? 'completed';
    $tone      = $clar['tone']       ?? 'calm'; // tone currently unused
    $createdAt = $clar['created_at'] ?? null;
    $updatedAt = $clar['updated_at'] ?? null;

    $isCompleted   = ($status === 'completed');
    $isCancellable = in_array($status, ['draft', 'in_progress'], true);

    $pageTitle = 'Your clarification result';

    // Expose variables to the view template
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
