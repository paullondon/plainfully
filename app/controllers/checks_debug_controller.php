<?php declare(strict_types=1);

/**
 * Debug controller for the `checks` table.
 * 
 * NOTE: ensureDebugAccess() is currently a no-op while you're building.
 */

function debug_list_checks(): void
{
    ensureDebugAccess();

    $pdo = pf_db();
    $stmt = $pdo->query("
        SELECT
            id,
            user_id,
            channel,
            source_identifier,
            short_summary,
            is_scam,
            is_paid,
            created_at
        FROM checks
        ORDER BY id DESC
        LIMIT 50
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    require dirname(__DIR__) . '/views/debug/checks_index.php';
    $inner = ob_get_clean();

    pf_render_shell('Debug – Recent checks', $inner);
}

function debug_view_check(): void
{
    ensureDebugAccess();

    $pdo = pf_db();

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        pf_render_shell('Debug – Check', '<p>Invalid check id.</p>');
        return;
    }

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
            created_at,
            updated_at
        FROM checks
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        pf_render_shell('Debug – Check', '<p>Check not found.</p>');
        return;
    }

    $aiJsonPretty = '';
    if (!empty($row['ai_result_json'])) {
        $decoded = json_decode((string)$row['ai_result_json'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $aiJsonPretty = json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } else {
            $aiJsonPretty = (string)$row['ai_result_json'];
        }
    }

    ob_start();
    require dirname(__DIR__) . '/views/debug/checks_view.php';
    $inner = ob_get_clean();

    pf_render_shell('Debug – Check #' . (int)$row['id'], $inner);
}
