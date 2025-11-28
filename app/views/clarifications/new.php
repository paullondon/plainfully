<?php declare(strict_types=1);

require_once __DIR__ . '/../../support/clarifications.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Allowed tones for the selector
const PLAINFULLY_TONES = ['calm', 'firm', 'professional'];

if ($method === 'POST') {
    handle_plainfully_clarification_submit();
} else {
    render_plainfully_clarification_form();
    return;
}

/**
 * Render the "new clarification" form.
 */
function render_plainfully_clarification_form(array $errors = [], array $old = []): void
{
    $text = $old['text'] ?? '';
    $tone = $old['tone'] ?? 'calm';

    $csrfToken = plainfully_csrf_token();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>New Clarification | Plainfully</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="/assets/css/app.css">
    </head>
    <body class="pf-page-body">
    <main class="pf-page-main">
        <section class="pf-card pf-card--narrow">
            <h1 class="pf-heading">Start a new clarification</h1>

            <?php if ($errors): ?>
                <div class="pf-alert pf-alert--error">
                    <ul>
                        <?php foreach ($errors as $message): ?>
                            <li><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="/clarifications/new" novalidate>
                <input type="hidden" name="_token"
                       value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                <div class="pf-field">
                    <label for="text" class="pf-label">
                        What do you want Plainfully to clarify?
                    </label>
                    <textarea
                        id="text"
                        name="text"
                        rows="10"
                        class="pf-textarea"
                        maxlength="12000"
                        required
                    ><?= htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                    <p class="pf-help">
                        Paste the email, letter or message you need help with.
                        Max 12,000 characters.
                    </p>
                </div>

                <div class="pf-field">
                    <label for="tone" class="pf-label">Tone</label>
                    <select id="tone" name="tone" class="pf-select" required>
                        <option value="calm"         <?= $tone === 'calm' ? 'selected' : '' ?>>Calm</option>
                        <option value="firm"         <?= $tone === 'firm' ? 'selected' : '' ?>>Firm</option>
                        <option value="professional" <?= $tone === 'professional' ? 'selected' : '' ?>>Professional</option>
                    </select>
                    <p class="pf-help">
                        This is the style Plainfully will use when rewriting.
                    </p>
                </div>

                <div class="pf-actions">
                    <button type="submit" class="pf-button pf-button--primary">
                        Generate clarification
                    </button>
                </div>
            </form>
        </section>
    </main>
    </body>
    </html>
    <?php
}

/**
 * Handle POST /clarifications/new
 */
function handle_plainfully_clarification_submit(): void
{
    if (!plainfully_verify_csrf_token($_POST['_token'] ?? null)) {
        render_plainfully_clarification_form(
            ['Your session expired. Please submit the form again.'],
            $_POST
        );
        return;
    }

    $text = trim((string)($_POST['text'] ?? ''));
    $tone = trim((string)($_POST['tone'] ?? ''));

    $errors = [];

    // Validation: text
    if ($text === '') {
        $errors[] = 'Please paste the text you want clarified.';
    } elseif (mb_strlen($text) > 12000) {
        $errors[] = 'Your text is too long. Please reduce it to 12,000 characters or fewer.';
    }

    // Validation: tone
    if (!in_array($tone, PLAINFULLY_TONES, true)) {
        $errors[] = 'Please choose a valid tone.';
    }

    if ($errors) {
        render_plainfully_clarification_form($errors, ['text' => $text, 'tone' => $tone]);
        return;
    }

    // 28-day TTL
    $ttlDays = 28;
    $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expires = $now->modify("+{$ttlDays} days")->format('Y-m-d H:i:s');

    // Get logged-in user id
    $userId    = plainfully_current_user_id();
    $emailHash = null;

    // (optional safety net – if this ever happens, something is wrong with auth)
    if ($userId === null) {
        http_response_code(500);
        echo 'Could not identify current user while saving clarification.';
        return;
    }

    $promptCiphertext = plainfully_encrypt($text);

    $stubOutputText = plainfully_generate_stub_output($text, $tone);

    $responseCiphertext = plainfully_encrypt($stubOutputText);

    $pdo = plainfully_pdo();

    try {
        $pdo->beginTransaction();

        // Insert into clarifications
        $insertClarification = $pdo->prepare("
            INSERT INTO clarifications (
                user_id,
                email_hash,
                status,
                source,
                tone,
                created_at,
                updated_at,
                expires_at
            ) VALUES (
                :user_id,
                :email_hash,
                'completed',
                'web',
                :tone,
                NOW(6),
                NOW(6),
                :expires_at
            )
        ");

        if ($userId === null) {
            $insertClarification->bindValue(':user_id', null, \PDO::PARAM_NULL);
        } else {
            $insertClarification->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        }

        if ($emailHash === null) {
            $insertClarification->bindValue(':email_hash', null, \PDO::PARAM_NULL);
        } else {
            $insertClarification->bindValue(':email_hash', $emailHash, \PDO::PARAM_LOB);
        }

        $insertClarification->bindValue(':tone', $tone);
        $insertClarification->bindValue(':expires_at', $expires);
        $insertClarification->execute();

        $clarificationId = (int)$pdo->lastInsertId();

        // Insert into clarification_details
        $insertDetail = $pdo->prepare("
            INSERT INTO clarification_details (
                clarification_id,
                role,
                sequence_no,
                prompt_ciphertext,
                clarification_ciphertext,
                model_response_ciphertext,
                redacted_summary_ciphertext,
                created_at,
                updated_at,
                expires_at
            ) VALUES (
                :clarification_id,
                'system',
                1,
                :prompt_ciphertext,
                NULL,
                :response_ciphertext,
                NULL,
                NOW(6),
                NOW(6),
                :expires_at
            )
        ");

        $insertDetail->bindValue(':clarification_id', $clarificationId, \PDO::PARAM_INT);
        $insertDetail->bindValue(':prompt_ciphertext', $promptCiphertext, \PDO::PARAM_LOB);
        $insertDetail->bindValue(':response_ciphertext', $responseCiphertext, \PDO::PARAM_LOB);
        $insertDetail->bindValue(':expires_at', $expires);
        $insertDetail->execute();

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[Plainfully] Failed to save clarification: ' . $e->getMessage());

        render_plainfully_clarification_form(
            ['We could not save your clarification just now. Please try again.'],
            ['text' => $text, 'tone' => $tone]
        );
        return;
    }

    // Redirect to view page
    header('Location: /clarifications/view?id=' . urlencode((string)$clarificationId), true, 302);
    exit;
}

/**
 * Temporary stub "AI output" so the flow works end-to-end.
 */
function plainfully_generate_stub_output(string $text, string $tone): string
{
    $toneLabel = match ($tone) {
        'firm'         => 'firm but fair',
        'professional' => 'clear and professional',
        default        => 'calm and reassuring',
    };

    return <<<TXT
[Plainfully preview – {$toneLabel} tone]

You asked Plainfully to clarify the following text:

---
{$text}
---

In the real system, this section will contain a carefully rewritten version of your message
in a {$toneLabel} tone, ready to send.
TXT;
}