<?php declare(strict_types=1);

/**
 * Clarifications controller
 *
 * - handle_clarifications_index(): list a user's recent clarifications
 * - handle_clarifications_new(): show new clarification form
 * - handle_clarifications_store(): create a new clarification record
 */

function handle_clarifications_index(): void
{
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId === null) {
        pf_redirect('/login');
    }

    try {
        $pdo = pf_db();
        $stmt = $pdo->prepare(
            'SELECT id, title, status, created_at
             FROM clarifications
             WHERE user_id = :uid
             ORDER BY created_at DESC
             LIMIT 50'
        );
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        $rows = [];
        error_log('Clarifications index failed: ' . $e->getMessage());
    }

    // Pull any flash messages then clear them
    $ok    = $_SESSION['clarifications_ok']    ?? null;
    $error = $_SESSION['clarifications_error'] ?? null;
    unset($_SESSION['clarifications_ok'], $_SESSION['clarifications_error']);

    ob_start();
    $clarifications = $rows;
    $flashOk        = $ok;
    $flashError     = $error;
    require dirname(__DIR__, 2) . '/app/views/clarifications_index.php';
    $inner = ob_get_clean();

    pf_render_shell('Your clarifications', $inner, [
        'clarifications' => $clarifications,
    ]);
}

function handle_clarifications_new(): void
{
    $userId = $_SESSION['user_id'] ?? null;
    if ($userId === null) {
        pf_redirect('/login');
    }

    // Pull + clear any error message
    $error = $_SESSION['clarifications_error'] ?? null;
    unset($_SESSION['clarifications_error']);

    ob_start();
    $flashError = $error;
    require dirname(__DIR__, 2) . '/app/views/clarifications_new.php';
    $inner = ob_get_clean();

    pf_render_shell('New clarification', $inner);
}

function handle_clarifications_store(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        pf_redirect('/clarifications');
    }

    pf_verify_csrf_or_abort();

    $userId = $_SESSION['user_id'] ?? null;
    if ($userId === null) {
        pf_redirect('/login');
    }

    $title = trim($_POST['title'] ?? '');
    $text  = trim($_POST['original_text'] ?? '');

    if ($text === '') {
        $_SESSION['clarifications_error'] = 'Please paste or type something to clarify.';
        pf_redirect('/clarifications/new');
    }

    try {
        $pdo = pf_db();
        $stmt = $pdo->prepare(
            'INSERT INTO clarifications (user_id, title, original_text, status)
             VALUES (:uid, :title, :text, :status)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':title'  => $title !== '' ? $title : null,
            ':text'   => $text,
            ':status' => 'pending',
        ]);

        $_SESSION['clarifications_ok'] = 'Your request has been saved.';
        pf_log_auth_event('clarification_created', (int)$userId, null, 'New clarification created');
    } catch (Throwable $e) {
        error_log('Clarification store failed: ' . $e->getMessage());
        $_SESSION['clarifications_error'] = 'We could not save that request. Please try again.';
    }

    pf_redirect('/clarifications');
}
