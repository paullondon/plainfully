<?php
declare(strict_types=1);

use App\Features\Checks\CheckInput;
use App\Features\Checks\CheckEngine;
use App\Features\Checks\DummyAiClient;

// Load CheckEngine feature classes (no composer yet)
require_once dirname(__DIR__) . '/features/checks/check_input.php';
require_once dirname(__DIR__) . '/features/checks/check_result.php';
require_once dirname(__DIR__) . '/features/checks/ai_client.php';
require_once dirname(__DIR__) . '/features/checks/check_engine.php';

/**
 * Require a logged-in user, otherwise bail.
 * Adjust redirect/logic to match your real login route if needed.
 */
function pf_require_logged_in_user(): int
{
    if (empty($_SESSION['user_id'])) {
        header('Location: /login');
        exit;
    }

    return (int) $_SESSION['user_id'];
}

/**
 * Small helper to load the current user row.
 */
function pf_get_current_user_row(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, email, phone FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('User record not found.');
    }

    return $row;
}

/**
 * GET /clarifications
 * List of all checks for the logged in user.
 */
function clarifications_index_controller(): void
{
    $userId = pf_require_logged_in_user();
    $pdo    = pf_db();

    $stmt = $pdo->prepare(
        'SELECT id, channel, short_summary, is_scam, is_paid, created_at
         FROM checks
         WHERE user_id = :user_id
         ORDER BY created_at DESC'
    );
    $stmt->execute([':user_id' => $userId]);
    $checks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    ob_start();
    /** @var array $checks */
    require dirname(__DIR__) . '/views/clarifications/index.php';
    $inner = ob_get_clean();

    pf_render_shell('Your clarifications', $inner);
}

/**
 * GET/POST /clarifications/new
 * Show form and on submit, run CheckEngine and redirect to /clarifications/view?id=...
 */
function clarifications_new_controller(): void
{
    $userId = pf_require_logged_in_user();
    $pdo    = pf_db();

    $userRow = pf_get_current_user_row($pdo, $userId);

    $error   = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $content = trim($_POST['content'] ?? '');

            if ($content === '') {
                throw new RuntimeException('Please paste a message to check.');
            }

            // Channel is "web" for now
            $channel          = 'web';
            $sourceIdentifier = $userRow['email'] ?: ($userRow['phone'] ?? 'web-user-' . $userId);
            $contentType      = 'text/plain';

            $input = new CheckInput(
                $channel,
                $sourceIdentifier,
                $contentType,
                $content,
                $userRow['email'] ?: null,
                $userRow['phone'] ?: null,
                null // provider_user_id
            );

            $aiClient    = new DummyAiClient();      // swap later for real AI client
            $checkEngine = new CheckEngine($pdo, $aiClient);

            // For now, all web clarifications treated as free tier
            $isPaid = false;

            $result = $checkEngine->run($input, $isPaid);

            // Redirect to the new result page
            header('Location: /clarifications/view?id=' . $result->checkId);
            exit;
        } catch (Throwable $t) {
            $error = $t->getMessage();
        }
    }

    ob_start();
    /** @var string|null $error */
    require dirname(__DIR__) . '/views/clarifications/new.php';
    $inner = ob_get_clean();

    pf_render_shell('New clarification', $inner);
}

/**
 * GET /clarifications/view?id=123
 * Show a single clarification = one row from checks.
 */
function clarifications_view_controller(): void
{
    $userId = pf_require_logged_in_user();
    $pdo    = pf_db();

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        throw new RuntimeException('Invalid clarification ID.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, user_id, channel, source_identifier, content_type,
                ai_result_json, short_summary, is_scam, is_paid, created_at
         FROM checks
         WHERE id = :id AND user_id = :user_id
         LIMIT 1'
    );
    $stmt->execute([
        ':id'      => $id,
        ':user_id' => $userId,
    ]);

    $check = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$check) {
        throw new RuntimeException('Clarification not found.');
    }

    $ai = json_decode($check['ai_result_json'], true);
    if (!is_array($ai)) {
        throw new RuntimeException('Could not decode AI result JSON.');
    }

    ob_start();
    /** @var array $check */
    /** @var array $ai */
    require dirname(__DIR__) . '/views/clarifications/view.php';
    $inner = ob_get_clean();

    pf_render_shell('Clarification result', $inner);
}
