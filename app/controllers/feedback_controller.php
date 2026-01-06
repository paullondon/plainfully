<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/feedback_controller.php
 * Purpose:
 *   Accept one-tap feedback from lifecycle emails.
 *
 * Design:
 *   - Guest-safe: no login required
 *   - Signed links: prevents random people submitting feedback for other users
 *   - One tap records rating; optional comment form is offered after
 *
 * Endpoints:
 *   GET  /feedback?u=ID&k=KIND&r=RATING&s=SIG
 *   POST /feedback  (comment submit; uses same signed params)
 *
 * Security:
 *   - Validates u/k/r values strictly
 *   - Verifies HMAC signature using APP_KEY
 *   - Uses prepared statements only
 * ============================================================
 */

function feedback_controller(string $method): void
{
    // ----------------------------
    // Pull + validate params
    // ----------------------------
    $u = (int)($_GET['u'] ?? $_POST['u'] ?? 0);
    $k = (string)($_GET['k'] ?? $_POST['k'] ?? '');
    $r = (string)($_GET['r'] ?? $_POST['r'] ?? '');
    $s = (string)($_GET['s'] ?? $_POST['s'] ?? '');

    $allowedKinds   = ['day20', 'single_day', 'dd_checkin', 'monthly_review'];
    $allowedRatings = ['up', 'meh', 'down'];

    if ($u <= 0 || !in_array($k, $allowedKinds, true) || !in_array($r, $allowedRatings, true) || $s === '') {
        http_response_code(400);
        pf_render_shell('Feedback', '<p>That feedback link looks incomplete or invalid.</p>');
        return;
    }

    // ----------------------------
    // Verify signature
    // ----------------------------
    $appKey = (string) getenv('APP_KEY');

    if ($appKey === '') {
        // Fail-closed: do not accept unsigned feedback without an APP_KEY.
        http_response_code(500);
        pf_render_shell('Feedback', '<p>Feedback is temporarily unavailable.</p>');
        return;
    }

    $expected = hash_hmac('sha256', $u . '|' . $k . '|' . $r, $appKey);

    if (!hash_equals($expected, $s)) {
        http_response_code(403);
        pf_render_shell('Feedback', '<p>That feedback link could not be verified.</p>');
        return;
    }

    // ----------------------------
    // DB handle (compatible fallback)
    // ----------------------------
    try {
        // Prefer your existing helper if available.
        if (function_exists('pf_db')) {
            $pdo = pf_db();
        } else {
            // Fallback: if bootstrap exposes $pdo in global scope.
            /** @var PDO|null $pdo */
            $pdo = $GLOBALS['pdo'] ?? null;
        }

        if (!$pdo instanceof PDO) {
            throw new RuntimeException('PDO not available');
        }
    } catch (Throwable $e) {
        http_response_code(500);
        pf_render_shell('Feedback', '<p>Feedback is temporarily unavailable.</p>');
        return;
    }

    // ----------------------------
    // If POST, store optional comment
    // ----------------------------
    if ($method === 'POST') {
        $comment = trim((string)($_POST['comment'] ?? ''));

        // Keep comments short to reduce abuse risk + storage.
        if (mb_strlen($comment) > 1000) {
            $comment = mb_substr($comment, 0, 1000);
        }

        try {
            // Update the most recent matching feedback row for this user/kind/rating.
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
            // Non-fatal: still show thank-you.
            error_log('[feedback] comment save failed: ' . $e->getMessage());
        }

        pf_render_shell('Thank you', '<p>Cheers — that helps a lot.</p>');
        return;
    }

    // ----------------------------
    // GET: record rating (idempotent-ish)
    // ----------------------------
    try {
        // If user mashes the same button multiple times, avoid duplicates within 10 minutes.
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
        // Still proceed to UX (don't punish user for DB hiccup).
    }

    // ----------------------------
    // Render thank-you + optional comment box
    // ----------------------------
    $inner = '
        <div class="pf-card">
            <h1 style="margin-top:0;">Thank you</h1>
            <p>Got it. If you want to add one sentence, it helps us improve the experience.</p>

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

            <p style="margin-top:16px;color:#6B7280;">No need to reply to the email — this is enough.</p>
        </div>
    ';

    pf_render_shell('Feedback', $inner);
}
