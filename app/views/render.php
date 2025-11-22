<?php declare(strict_types=1);

/**
 * View rendering helpers for Plainfully
 */

/**
 * Render a basic container page.
 */
function pf_render_shell(string $title, string $innerHtml): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="/assets/css/app.css">
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    </head>
    <body>
    <main class="pf-auth-shell">
        <section class="pf-auth-card">
            <?= $innerHtml ?>
        </section>
    </main>
    </body>
    </html>
    <?php
}
