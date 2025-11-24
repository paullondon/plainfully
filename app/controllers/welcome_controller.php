<?php declare(strict_types=1);

/**
 * Main controller for Plainfully
 *
 * Handles:
 *  - Home page (logged-in welcome)
 */

function handle_welcome(): void
{
    $userId = $_SESSION['user_id'] ?? null;

    // If not logged in, this should never be called because require_login() blocks it.
    if (!$userId) {
        pf_redirect('/login');
    }

    // make user available to the view
    $userId = (int)$userId;

    ob_start();
    require __DIR__ . '/../views/home.php';
    $inner = ob_get_clean();

    pf_render_shell('Plainfully', $inner);
}