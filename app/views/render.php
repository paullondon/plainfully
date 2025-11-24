<?php declare(strict_types=1);

function pf_render_shell(string $title, string $innerHtml, array $data = []): void
{
    $isLoggedIn = isset($_SESSION['user_id']);

    // Distinct classes for logged-in vs auth pages
    $bodyClass = $isLoggedIn ? 'pf-shell-loggedin' : 'pf-shell-auth';
    $mainClass = $isLoggedIn ? 'pf-main'           : 'pf-auth-card';

    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($title) ?> | Plainfully</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="stylesheet" href="/assets/css/app.css">
    </head>

    <body class="<?= $bodyClass ?>">

    <?php if ($isLoggedIn): ?>
        <nav class="pf-topnav">
            <div class="pf-topnav-left">
                <a href="/dashboard" class="pf-brand">Plainfully</a>
                <a href="/clarifications" class="pf-nav-link">Clarifications</a>
            </div>
            <div class="pf-topnav-right">
                <form action="/logout" method="POST">
                    <?php pf_csrf_field(); ?>
                    <button class="pf-nav-button">Logout</button>
                </form>
            </div>
        </nav>
    <?php endif; ?>

        <main class="<?= $mainClass ?>">
            <?= $innerHtml ?>
        </main>

    </body>
    </html>
    <?php
}
