<?php declare(strict_types=1);

/**
 * Plainfully – Debug views for `checks` table.
 *
 * Requires ensureDebugAccess() to guard access.
 */

function debug_list_checks(): void
{
    ensureDebugAccess();
    $pdo = pf_db();

    // Fetch latest 50 checks (no raw content, only summaries)
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
    ?>
    <h1 class="pf-page-title">Debug – Recent checks</h1>
    <p class="pf-page-subtitle">
        Latest 50 entries from the <code>checks</code> table (no raw content).
    </p>

    <table class="pf-table pf-table-debug">
        <thead>
        <tr>
            <th>ID</th>
            <th>Channel</th>
            <th>Source</th>
            <th>Is scam?</th>
            <th>Paid?</th>
            <th>Short summary</th>
            <th>Created</th>
            <th>View</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><?= htmlspecialchars((string)$row['channel'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)$row['source_identifier'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= ((int)$row['is_scam'] === 1) ? 'Yes' : 'No' ?></td>
                <td><?= ((int)$row['is_paid'] === 1) ? 'Yes' : 'No' ?></td>
                <td>
                    <?= htmlspecialchars((string)$row['short_summary'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <a href="/debug/checks/view?id=<?= (int)$row['id'] ?>">View</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    $inner = ob_get_clean();

    pf_render_shell('Debug – checks', $inner);
}

/**
 * View a single check – still no raw content, only AI result JSON.
 */
function debug_view_check(): void
{
    ensureDebugAccess();
    $pdo = pf_db();

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        pf_render_shell('Debug – checks', '<p>Invalid check id.</p>');
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
        pf_render_shell('Debug – checks', '<p>Check not found.</p>');
        return;
    }

    $aiJsonPretty = '';
    if (!empty($row['ai_result_json'])) {
        $decoded = json_decode((string)$row['ai_result_json'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $aiJsonPretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $aiJsonPretty = (string)$row['ai_result_json'];
        }
    }

    ob_start();
    ?>
    <h1 class="pf-page-title">Debug – Check #<?= (int)$row['id'] ?></h1>

    <dl class="pf-debug-dl">
        <dt>Channel</dt>
        <dd><?= htmlspecialchars((string)$row['channel'], ENT_QUOTES, 'UTF-8') ?></dd>

        <dt>Source identifier</dt>
        <dd><?= htmlspecialchars((string)$row['source_identifier'], ENT_QUOTES, 'UTF-8') ?></dd>

        <dt>Is scam?</dt>
        <dd><?= ((int)$row['is_scam'] === 1) ? 'Yes' : 'No' ?></dd>

        <dt>Paid?</dt>
        <dd><?= ((int)$row['is_paid'] === 1) ? 'Yes' : 'No' ?></dd>

        <dt>Short summary</dt>
        <dd><?= nl2br(htmlspecialchars((string)$row['short_summary'], ENT_QUOTES, 'UTF-8')) ?></dd>

        <dt>Created</dt>
        <dd><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></dd>

        <dt>Updated</dt>
        <dd><?= htmlspecialchars((string)$row['updated_at'], ENT_QUOTES, 'UTF-8') ?></dd>
    </dl>

    <h2 class="pf-page-subtitle">AI result JSON</h2>
    <pre class="pf-debug-pre"><?= htmlspecialchars($aiJsonPretty, ENT_QUOTES, 'UTF-8') ?></pre>
    <?php
    $inner = ob_get_clean();

    pf_render_shell('Debug – check #' . (int)$row['id'], $inner);
}
