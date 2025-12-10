<?php declare(strict_types=1);

use App\Features\Checks\CheckInput;
use App\Features\Checks\CheckEngine;
use App\Features\Checks\DummyAiClient;

// Feature classes
require_once dirname(__DIR__) . '/features/checks/check_input.php';
require_once dirname(__DIR__) . '/features/checks/check_result.php';
require_once dirname(__DIR__) . '/features/checks/ai_client.php';
require_once dirname(__DIR__) . '/features/checks/check_engine.php';

/**
 * Helper: current user id + email from session.
 */
function pf_current_user_context(): array
{
    $userId    = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $userEmail = isset($_SESSION['user_email']) ? (string)$_SESSION['user_email'] : '';

    return [$userId, $userEmail];
}

/**
 * GET /clarifications/new
 * POST /clarifications/new
 */
function clarifications_new_controller(): void
{
    require_login();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $errors   = [];
    $oldText  = '';
    $oldTone  = 'calm';

    if ($method === 'POST') {
        $oldText = trim($_POST['text'] ?? '');
        $oldTone = trim($_POST['tone'] ?? 'calm');

        // Basic validation
        if ($oldText === '') {
            $errors[] = 'Please paste or type the message you want Plainfully to check.';
        } elseif (mb_strlen($oldText) < 10) {
            $errors[] = 'The text is very short. Please include enough detail for us to analyse it properly.';
        } elseif (mb_strlen($oldText) > 8000) {
            $errors[] = 'The text is too long. Please trim it down or split into multiple checks.';
        }

        if (empty($errors)) {
            // Build CheckEngine input
            [$userId, $userEmail] = pf_current_user_context();

            $pdo       = pf_db();
            $aiClient  = new DummyAiClient();
            $engine    = new CheckEngine($pdo, $aiClient);

            // Channel = web, source identifier = user email (or user id)
            $sourceIdentifier = $userEmail !== '' ? $userEmail : ('user#' . $userId);

            // NOTE: raw content goes to CheckEngine; it is never stored as raw in DB.
            $input = new CheckInput(
                'web',              // channel
                $sourceIdentifier,  // source_identifier
                'text/plain',       // content_type
                $oldText,           // raw content (engine applies safety layer)
                $userEmail ?: null, // email
                null,               // phone
                null                // provider_user_id
            );

            // TODO: hook in billing / plan here
            $isPaid = false;

            try {
                $result = $engine->run($input, $isPaid);

                // On success, redirect to the unified view
                $checkId = $result->id;
                pf_redirect('/clarifications/view?id=' . $checkId);
                return;
            } catch (Throwable $e) {
                // Graceful failure – do NOT expose stack trace to user
                error_log('CheckEngine error (web): ' . $e->getMessage());
                $errors[] = 'Something went wrong while analysing your text. Please try again in a moment.';
            }
        }
    }

    // Render the form (GET, or POST with validation errors)
    ob_start();
    require dirname(__DIR__) . '/views/clarifications/new.php';
    $inner = ob_get_clean();

    pf_render_shell('New clarification', $inner);
}

/**
 * GET /clarifications
 * List checks for the logged-in user.
 */
function clarifications_index_controller(): void
{
    require_login();
    [$userId, $userEmail] = pf_current_user_context();

    $pdo = pf_db();

    $stmt = $pdo->prepare("
        SELECT id, channel, short_summary, is_scam, is_paid, created_at
        FROM checks
        WHERE user_id = :uid
        ORDER BY id DESC
        LIMIT 100
    ");
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    require dirname(__DIR__) . '/views/clarifications/index.php';
    $inner = ob_get_clean();

    pf_render_shell('Your clarifications', $inner);
}

/**
 * GET /clarifications/view?id=123
 * Show single clarification result.
 */
function clarifications_view_controller(): void
{
    require_login();
    [$userId, $userEmail] = pf_current_user_context();

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        pf_render_shell('Clarification', '<p>Invalid clarification id.</p>');
        return;
    }

    $pdo = pf_db();

    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            channel,
            source_identifier,
            short_summary,
            ai_result_json,
            is_scam,
            is_paid,
            created_at
        FROM checks
        WHERE id = :id AND user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([
        ':id'  => $id,
        ':uid' => $userId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        pf_render_shell('Clarification', '<p>Clarification not found.</p>');
        return;
    }

    // Parse AI JSON into PHP array with safe fallbacks
    $ai = [];
    if (!empty($row['ai_result_json'])) {
        $decoded = json_decode((string)$row['ai_result_json'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $ai = $decoded;
        }
    }

    // Derive view-friendly pieces
    $plan = $ai['plan'] ?? [];
    $viewModel = [
        'check' => $row,
        'plan'  => [
            'name'     => $plan['name'] ?? 'Free plan',
            'used'     => $plan['clarifications_used']  ?? null,
            'limit'    => $plan['clarifications_limit'] ?? null,
            'resetsAt' => $plan['resets_at'] ?? null,
        ],
        'key_points' => $ai['key_points'] ?? [
            $ai['short_verdict'] ?? $row['short_summary'],
        ],
        'risks' => $ai['risks'] ?? [],
        'next_steps' => $ai['typical_next_steps'] ?? [],
        'short_verdict' => $ai['short_verdict'] ?? $row['short_summary'],
    ];

    ob_start();
    // Expose $viewModel and $ai to the view
    $vm = $viewModel;
    require dirname(__DIR__) . '/views/clarifications/view.php';
    $inner = ob_get_clean();

    pf_render_shell('Clarification result', $inner);
}
