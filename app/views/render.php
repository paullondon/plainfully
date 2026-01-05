<?php declare(strict_types=1);

function pf_render_shell(string $title, string $innerHtml, array $data = []): void
{
    // Ensure session is available before reading $_SESSION
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

    // Bring $config in from bootstrap/app.php
    global $config;

    $isLoggedIn = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;

    // Admin detection (fail-closed if helper missing)
    // $isAdmin = (function_exists('pf_is_admin') && $isLoggedIn) ? (bool)pf_is_admin() : false;
    $isAdmin = function_exists('pf_is_admin') ? pf_is_admin() : false;

    // --- User tier badge (order: Admin > Unlimited > Basic) ---

    $tierLabel = 'Basic';
    $tierBg    = '#6b7280'; // grey
    $tierFg    = '#ffffff';

    if (function_exists('pf_is_admin') && pf_is_admin()) {
        $tierLabel = 'Admin';
        $tierBg    = '#b91c1c'; // red
    } elseif (!empty($_SESSION['user_plan']) && $_SESSION['user_plan'] === 'unlimited') {
        $tierLabel = 'Unlimited';
        $tierBg    = '#b45309'; // amber (darker for contrast)
    }

    // ----------------------------------------------------------

    $bodyClass = $isLoggedIn ? 'pf-shell-loggedin' : 'pf-shell-auth';
    $mainClass = $isLoggedIn ? 'pf-main'           : 'pf-auth-card';

    // Defensive: default CSS version if config is missing
    $cssVersion = htmlspecialchars((string)($config['app']['css'] ?? '1'), ENT_QUOTES, 'UTF-8');

    // Optional: display email (safe + optional)
    $userEmail = $isLoggedIn ? (string)($_SESSION['user_email'] ?? '') : '';
    $userEmailSafe = htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8');
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> | Plainfully</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="stylesheet" href="/assets/css/theme.css">
        <link rel="stylesheet" href="/assets/css/base.css">
        <link rel="stylesheet" href="/assets/css/components/card.css">
        <link rel="stylesheet" href="/assets/css/components/button.css">
        <link rel="stylesheet" href="/assets/css/components/error.css">
        <link rel="stylesheet" href="/assets/css/components/admin.css">
        <link rel="stylesheet" href="/assets/css/app.css?v=<?= $cssVersion ?>">

        <style>
            /* Tiny shell bar (kept here for speed; move to app.css later) */
            .pf-shellbar{display:flex;align-items:center;justify-content:space-between;margin:0 0 1rem 0;}
            .pf-shellbar-right{display:flex;align-items:center;gap:.5rem;}
            .pf-admin-badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--pf-border);background:var(--pf-surface);color:var(--pf-text);font-size:12px;letter-spacing:.06em;}
            .pf-shell-email{font-size:12px;color:var(--pf-text-muted);}
        </style>
    </head>
    <body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($isLoggedIn): ?>
        <div style="
            position:fixed;
            top:12px;
            right:16px;
            font-size:12px;
            padding:6px 10px;
            border-radius:999px;
            background:<?= htmlspecialchars($tierBg, ENT_QUOTES, 'UTF-8') ?>;
            color:<?= htmlspecialchars($tierFg, ENT_QUOTES, 'UTF-8') ?>;
            z-index:1000;
        ">
            <?= htmlspecialchars($tierLabel, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
   
    <main class="<?= htmlspecialchars($mainClass, ENT_QUOTES, 'UTF-8') ?>">

            <?php if ($isLoggedIn): ?>
                <div class="pf-shellbar">
                    <div class="pf-shellbar-left">
                        <a href="/dashboard" style="text-decoration:none;color:inherit;">
                            <strong>Plainfully</strong>
                        </a>
                    </div>

                </div>
            <?php endif; ?>

            <?= $innerHtml ?>
        </main>
    </body>
    </html>
    <?php
}
