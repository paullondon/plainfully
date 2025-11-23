<?php declare(strict_types=1);

function handle_dashboard(): void
{
    // Ensure logged in
    if (!isset($_SESSION['user_id'])) {
        pf_redirect('/login');
    }

    $userId = $_SESSION['user_id'];

    // In the future: fetch user profile, settings, etc.
    $data = [
        'userId' => $userId,
    ];

    ob_start();
    require dirname(__DIR__, 2) . '/app/views/dashboard.php';
    $inner = ob_get_clean();

    pf_render_shell('Dashboard', $inner, $data);
}
