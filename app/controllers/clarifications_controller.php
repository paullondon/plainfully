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

    // Normalise + hash the text for dedupe
    $textHash = pf_clarification_text_hash($text);

    // 1) Check for duplicate in last 28 days
    $duplicate = pf_find_duplicate_clarification_28d((int)$userId, $textHash);
    if ($duplicate) {
        // Be open and honest: don’t consume quota, just point them at the existing one.
        $_SESSION['clarifications_error'] =
            'You have already asked this recently. '
            . 'View your existing clarification instead of using another request.';

        // (Later we can add a direct link like `/clarifications/{id}`)
        pf_redirect('/clarifications');
    }

    // 2) Quota check (rolling 28 days)
    $limits   = pf_get_user_plan_limits((int)$userId);
    $used     = pf_count_user_clarifications_28d((int)$userId);
    $max_28d  = $limits['max_28d'];

    if ($max_28d !== null && $used >= $max_28d) {
        $_SESSION['clarifications_error'] =
            'You have reached your ' . $limits['plan'] . ' plan limit of '
            . $max_28d . ' clarifications in 28 days. '
            . 'Upgrade your plan to submit more.';
        pf_redirect('/clarifications');
    }

    // 3) Store clarification
    try {
        $pdo = pf_db();
        $stmt = $pdo->prepare(
            'INSERT INTO clarifications (user_id, title, original_text, text_hash, status)
             VALUES (:uid, :title, :text, :hash, :status)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':title'  => $title !== '' ? $title : null,
            ':text'   => $text,
            ':hash'   => $textHash,
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

