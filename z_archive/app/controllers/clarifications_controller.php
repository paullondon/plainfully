<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/clarifications_controller.php
 * Purpose:
 *   Web clarification flow (new, list, view) with trace support.
 *
 * Tracing:
 *   - One trace_id per clarification run
 *   - Stages: ingest → prep → ai → output
 *   - Trace is ALWAYS written when enabled (no sampling)
 * ============================================================
 */

use App\Features\Checks\CheckInput;
use App\Features\Checks\CheckEngine;
use App\Features\Checks\DummyAiClient;

require_once dirname(__DIR__) . '/features/checks/check_input.php';
require_once dirname(__DIR__) . '/features/checks/check_result.php';
require_once dirname(__DIR__) . '/features/checks/ai_client.php';
require_once dirname(__DIR__) . '/features/checks/check_engine.php';
require_once dirname(__DIR__) . '/support/trace.php';

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

    $pdo = pf_db();
    $traceId = pf_trace_enabled() ? pf_trace_new_id() : '';

    pf_trace($pdo, $traceId, 'info', 'ingest', 'enter', 'Clarification form opened');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $errors   = [];
    $oldText  = '';
    $oldTone  = 'calm';

    if ($method === 'POST') {
        $oldText = trim($_POST['text'] ?? '');
        $oldTone = trim($_POST['tone'] ?? 'calm');

        pf_trace($pdo, $traceId, 'info', 'ingest', 'submit', 'Form submitted', [
            'text_len' => strlen($oldText),
            'tone'     => $oldTone,
        ]);

        if ($oldText === '') {
            $errors[] = 'Please paste or type the message you want Plainfully to check.';
        } elseif (mb_strlen($oldText) < 10) {
            $errors[] = 'The text is very short.';
        } elseif (mb_strlen($oldText) > 8000) {
            $errors[] = 'The text is too long.';
        }

        if (empty($errors)) {
            [$userId, $userEmail] = pf_current_user_context();

            pf_trace($pdo, $traceId, 'info', 'prep', 'context', 'User context resolved', [
                'user_id' => $userId,
                'email'   => $userEmail !== '' ? 'present' : 'missing',
            ]);

            $aiClient = new DummyAiClient();
            $engine   = new CheckEngine($pdo, $aiClient);

            $sourceIdentifier = $userEmail !== '' ? $userEmail : ('user#' . $userId);

            $input = new CheckInput(
                'web',
                $sourceIdentifier,
                'text/plain',
                $oldText,
                $userEmail ?: null,
                null,
                ['trace_id' => $traceId]
            );

            try {
                pf_trace($pdo, $traceId, 'info', 'ai', 'run', 'AI analysis starting');

                $result = $engine->run($input, false);

                pf_trace($pdo, $traceId, 'info', 'output', 'stored', 'Clarification stored', [
                    'check_id' => $result->id,
                ]);

                pf_redirect('/clarifications/view?id=' . (int)$result->id);
                return;

            } catch (Throwable $e) {
                pf_trace($pdo, $traceId, 'error', 'ai', 'exception', 'AI processing failed', [
                    'error' => $e->getMessage(),
                ]);
                error_log('CheckEngine error (web): ' . $e->getMessage());
                $errors[] = 'Something went wrong. Please try again.';
            }
        }
    }

    ob_start();
    require dirname(__DIR__) . '/views/clarifications/new.php';
    $inner = ob_get_clean();

    pf_render_shell('New clarification', $inner);
}

/**
 * GET /clarifications
 */
function clarifications_index_controller(): void
{
    require_login();
    [$userId] = pf_current_user_context();

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
 */
function clarifications_view_controller(): void
{
    require_login();
    [$userId] = pf_current_user_context();

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        pf_render_shell('Clarification', '<p>Invalid clarification id.</p>');
        return;
    }

    $pdo = pf_db();
    $stmt = $pdo->prepare("
        SELECT *
        FROM checks
        WHERE id = :id AND user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([':id' => $id, ':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        pf_render_shell('Clarification', '<p>Clarification not found.</p>');
        return;
    }

    $ai = [];
    if (!empty($row['ai_result_json'])) {
        $decoded = json_decode((string)$row['ai_result_json'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $ai = $decoded;
        }
    }

    $vm = [
        'check' => $row,
        'ai'    => $ai,
    ];

    ob_start();
    require dirname(__DIR__) . '/views/clarifications/view.php';
    $inner = ob_get_clean();

    pf_render_shell('Clarification result', $inner);
}
