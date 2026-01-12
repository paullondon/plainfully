<?php
// app/support/debug_consultations.php

declare(strict_types=1);

/**
 * Debug helpers for listing and viewing consultations.
 * These should ONLY be reachable via routes protected by ensureDebugAccess().
 *
 * Assumptions you may need to adapt:
 * - There is a global db() function returning a PDO instance.
 * - There is a decrypt_payload(string $ciphertext): string helper for consultation_details.
 */

require_once __DIR__ . '/debug_guard.php';

if (!function_exists('debug_list_consultations')) {
    function debug_list_consultations(): void
    {
        ensureDebugAccess();

        try {
            $pdo = pf_db(); // adapt if your DB helper is named differently

            $stmt = $pdo->query(
                'SELECT id, created_at 
                   FROM consultations 
               ORDER BY id DESC 
                  LIMIT 50'
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'DB error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            return;
        }

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Debug: Recent Consultations</title>
            <style>
                body {
                    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                    background: #111827;
                    color: #E5E7EB;
                    padding: 24px;
                }
                a { color: #38BDF8; }
                .list-item {
                    padding: 6px 0;
                    border-bottom: 1px solid #1F2937;
                }
            </style>
        </head>
        <body>
        <h1>Debug: Recent Consultations</h1>
        <p>Showing the 50 most recent consultations.</p>
        <ul>
            <?php foreach ($rows as $row): ?>
                <li class="list-item">
                    #<?= (int)$row['id']; ?> —
                    <?= htmlspecialchars($row['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    —
                    <a href="/debug/consultations/view?debug_token=<?= urlencode($_GET['debug_token'] ?? ''); ?>&id=<?= (int)$row['id']; ?>">
                        View
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        </body>
        </html>
        <?php
    }
}

if (!function_exists('debug_view_consultation')) {
    function debug_view_consultation(): void
    {
        ensureDebugAccess();

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo 'Missing or invalid id';
            return;
        }

        try {
            $pdo = pf_db(); // adapt if needed

            // consultation row
            $stmt = $pdo->prepare(
                'SELECT * 
                   FROM consultations 
                  WHERE id = :id 
                  LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $consultation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$consultation) {
                http_response_code(404);
                echo 'Consultation not found';
                return;
            }

            // consultation_details row
            $stmt = $pdo->prepare(
                'SELECT * 
                   FROM consultation_details 
                  WHERE consultation_id = :id 
                  LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
            $details = $stmt->fetch(PDO::FETCH_ASSOC);

            $decrypted = null;
            if ($details && !empty($details['encrypted_payload'] ?? '')) {
                $decrypted = plainfully_decrypt($details['encrypted_payload']);
            }

        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            return;
        }

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Debug Consultation #<?= (int)$id; ?></title>
            <style>
                body {
                    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                    background: #020617;
                    color: #E5E7EB;
                    padding: 24px;
                }
                pre {
                    white-space: pre-wrap;
                    background: #030712;
                    color: #E5E7EB;
                    padding: 12px;
                    border-radius: 6px;
                    border: 1px solid #1F2937;
                    max-width: 100%;
                    overflow-x: auto;
                }
                a { color: #38BDF8; }
            </style>
        </head>
        <body>
        <h1>Debug Consultation #<?= (int)$id; ?></h1>

        <p>
            <a href="/debug/consultations?debug_token=<?= urlencode($_GET['debug_token'] ?? ''); ?>">← Back to list</a>
        </p>

        <h2>consultations row</h2>
        <pre><?= htmlspecialchars(print_r($consultation, true), ENT_QUOTES, 'UTF-8'); ?></pre>

        <h2>consultation_details (decrypted)</h2>
        <?php if ($decrypted !== null): ?>
            <pre><?= htmlspecialchars($decrypted, ENT_QUOTES, 'UTF-8'); ?></pre>
        <?php else: ?>
            <p><em>No details row found, or decryption failed.</em></p>
        <?php endif; ?>

        </body>
        </html>
        <?php
    }
}
