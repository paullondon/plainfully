<?php declare(strict_types=1);

/**
 * Debug-only shell renderer.
 *
 * Used for /debug/* pages so they are not constrained
 * by the normal Plainfully "card" layout.
 */

function pf_render_debug_shell(string $title, string $innerHtml): void
{
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <!-- Reuse your main CSS so it still looks like Plainfully -->
        <link rel="stylesheet" href="/assets/css/app.css">
    </head>
    <body class="pf-debug-body" style="background: var(--pf-bg); color: var(--pf-text-main);">

        <?= $innerHtml ?>

    </body>
    </html>
    <?php
}
