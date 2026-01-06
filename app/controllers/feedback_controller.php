<?php declare(strict_types=1);

function feedback_controller(string $method): void
{
    $u = (int)($_GET['u'] ?? $_POST['u'] ?? 0);
    $k = (string)($_GET['k'] ?? $_POST['k'] ?? '');
    $r = (string)($_GET['r'] ?? $_POST['r'] ?? '');
    $s = (string)($_GET['s'] ?? $_POST['s'] ?? '');

    $allowedKinds   = ['day20', 'single_day', 'dd_checkin', 'monthly_review'];
    $allowedRatings = ['up', 'meh', 'down'];

    if ($u <= 0 || !in_array($k, $allowedKinds, true) || !in_array($r, $allowedRatings, true) || $s === '') {
        http_response_code(400);
        pf_render_shell('Feedback', '<p>That feedback link looks incomplete or invalid.</p><p style="color:#6B7280;">You can close this tab.</p>');
        return;
    }

    $appKey = (string) getenv('APP_KEY');
    if ($appKey === '') {
        http_response_code(500);
        pf_render_shell('Feedback', '<p>Feedback is temporarily unavailable.</p><p style="color:#6B7280;">You can close this tab.</p>');
        return;
    }

    $expected = hash_hmac('sha256', $u . '|' . $k . '|' . $r, $appKey);
    if (!hash_equals($expected, $s)) {
        http_response_code(403);
        pf_render_shell('Feedback', '<p>That feedback link could not be verified.</p><p style="color:#6B7280;">You can close this tab.</p>');
        return;
    }

    try {
        if (function_exists('pf_db')) {
            $pdo = pf_db();
        } else {
            $pdo = $GLOBALS['pdo'] ?? null;
        }
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('PDO not available');
        }
    } catch (Throwable $e) {
        http_response_code(500);
        pf_render_shell('Feedback', '<p>Feedback is temporarily unavailable.</p><p style="color:#6B7280;">You can close this tab.</p>');
        return;
    }

    if ($method === 'POST') {
        $comment = trim((string)($_POST['comment'] ?? ''));
        if (mb_strlen($comment) > 1000) {
            $comment = mb_substr($comment, 0, 1000);
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE user_feedback
                SET comment = :c
                WHERE user_id = :u AND kind = :k AND rating = :r
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':c' => ($comment !== '' ? $comment : null),
                ':u' => $u,
                ':k' => $k,
                ':r' => $r,
            ]);
        } catch (Throwable $e) {
            error_log('[feedback] comment save failed: ' . $e->getMessage());
        }

        $inner = '
            <div class="pf-card">
                <h1 style="margin-top:0;">Thank you</h1>
                <p>Cheers — that helps a lot.</p>
                <p style="color:#6B7280;">You can close this tab.</p>
                <p><a href="/login">Or log in to Plainfully</a></p>
            </div>
        ';

        pf_render_shell('Thank you', $inner);
        return;
    }

    try {
        $dupe = $pdo->prepare("
            SELECT id
            FROM user_feedback
            WHERE user_id = :u AND kind = :k AND rating = :r
              AND created_at >= (NOW() - INTERVAL 10 MINUTE)
            LIMIT 1
        ");
        $dupe->execute([':u' => $u, ':k' => $k, ':r' => $r]);
        $existing = $dupe->fetchColumn();

        if (!$existing) {
            $ins = $pdo->prepare("
                INSERT INTO user_feedback (user_id, kind, rating)
                VALUES (:u, :k, :r)
            ");
            $ins->execute([':u' => $u, ':k' => $k, ':r' => $r]);
        }
    } catch (Throwable $e) {
        error_log('[feedback] insert failed: ' . $e->getMessage());
    }

    $inner = '
        <div class="pf-card">
            <h1 style="margin-top:0;">Thank you</h1>
            <p>Got it.</p>
            <p>If you want to add one sentence, it helps us improve the experience.</p>

            <form method="post" action="/feedback">
                <input type="hidden" name="u" value="' . htmlspecialchars((string)$u) . '">
                <input type="hidden" name="k" value="' . htmlspecialchars($k) . '">
                <input type="hidden" name="r" value="' . htmlspecialchars($r) . '">
                <input type="hidden" name="s" value="' . htmlspecialchars($s) . '">

                <textarea name="comment" rows="4" style="width:100%;max-width:100%;padding:10px;border:1px solid #E5E7EB;border-radius:10px;" placeholder="Optional — what could be clearer?"></textarea>

                <div style="margin-top:12px;">
                    <button type="submit" class="pf-btn">Send feedback</button>
                </div>
            </form>

            <p style="margin-top:16px;color:#6B7280;">You can close this tab.</p>
            <p><a href="/login">Or log in to Plainfully</a></p>
        </div>
    ';

    pf_render_shell('Feedback', $inner);
}
