<?php declare(strict_types=1);

function pf_render_shell(string $title, string $innerHtml, array $data = []): void
{
    // Bring $config in from bootstrap/app.php
    global $config;

    $isLoggedIn = isset($_SESSION['user_id']);

    $bodyClass = $isLoggedIn ? 'pf-shell-loggedin' : 'pf-shell-auth';
    $mainClass = $isLoggedIn ? 'pf-main'           : 'pf-auth-card';

    // Defensive: default CSS version if config is missing
    $cssVersion = htmlspecialchars((string)($config['css'] ?? '1'), ENT_QUOTES, 'UTF-8');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($title) ?> | Plainfully</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet"
              href="/assets/css/app.css?v=<?= $cssVersion ?>">
    </head>
    <body class="<?= $bodyClass ?>">
        <main class="<?= $mainClass ?>">
            <?= $innerHtml ?>
        </main>
    </body>
    </html>
    <?php
}
