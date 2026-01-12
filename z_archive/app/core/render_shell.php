<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully Render Shell
 * ============================================================
 * File: app/core/render_shell.php
 * Purpose:
 *   Shared HTML shell for web pages (auth + logged-in).
 *
 * Notes:
 * - Uses CSS tokens (theme.css) for light/dark mode.
 * - Enforces correct logo variant via <picture> + prefers-color-scheme.
 * - Soft-fail philosophy: never throw output-breaking errors to users.
 * ============================================================
 */

function pf_render_shell(string $title, string $innerHtml, array $data = []): void
{
    // ---------------------------------------------------------
    // 0) Session (safe start)
    // ---------------------------------------------------------
    if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }

    // ---------------------------------------------------------
    // 1) Config (prefer $GLOBALS to avoid fragile "global $config")
    // ---------------------------------------------------------
    $config = is_array($GLOBALS['config'] ?? null) ? $GLOBALS['config'] : [];

    $isLoggedIn = isset($_SESSION['user_id']) && (int)($_SESSION['user_id']) > 0;

    // Admin detection (fail-closed)
    $isAdmin = (function_exists('pf_is_admin') && $isLoggedIn) ? (bool)pf_is_admin() : false;

    // ---------------------------------------------------------
    // 2) Tier badge (Admin > Unlimited > Basic)
    // ---------------------------------------------------------
    $tierLabel = 'Basic';
    $tierClass = 'pf-tier-basic';

    if ($isAdmin) {
        $tierLabel = 'Admin';
        $tierClass = 'pf-tier-admin';
    } elseif (!empty($_SESSION['user_plan']) && $_SESSION['user_plan'] === 'unlimited') {
        $tierLabel = 'Unlimited';
        $tierClass = 'pf-tier-unlimited';
    }

    $bodyClass = $isLoggedIn ? 'pf-shell-loggedin' : 'pf-shell-auth';
    $mainClass = $isLoggedIn ? 'pf-main' : 'pf-auth-card';

    // Defensive: default CSS version if config is missing
    $cssVersion = htmlspecialchars((string)($config['app']['css'] ?? '1'), ENT_QUOTES, 'UTF-8');

    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title><?= $safeTitle ?> | Plainfully</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <link rel="stylesheet" href="/assets/css/theme.css">
        <link rel="stylesheet" href="/assets/css/base.css">
        <link rel="stylesheet" href="/assets/css/components/card.css">
        <link rel="stylesheet" href="/assets/css/components/button.css">
        <link rel="stylesheet" href="/assets/css/components/error.css">
        <link rel="stylesheet" href="/assets/css/components/admin.css">
        <link rel="stylesheet" href="/assets/css/app.css?v=<?= $cssVersion ?>">

        <style>
            /* Shell layout helpers (move to app.css once tidy is complete) */
            .pf-shellbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 1rem 0;}
            .pf-shellbar-left{display:flex;align-items:center;gap:10px;min-width:0;}
            .pf-brandlink{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;min-width:0;}
            .pf-brandword{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
            .pf-logo{height:26px;width:auto;display:block;}
            .pf-tier{position:fixed;top:12px;right:16px;font-size:12px;padding:6px 10px;border-radius:999px;z-index:1000;}
            .pf-tier-basic{background:var(--pf-border);color:var(--pf-text);}
            .pf-tier-unlimited{background:var(--pf-accent);color:#111827;}
            .pf-tier-admin{background:#b91c1c;color:#ffffff;}
        </style>
    </head>
    <body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>">

    <?php if ($isLoggedIn): ?>
        <div class="pf-tier <?= htmlspecialchars($tierClass, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($tierLabel, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <main class="<?= htmlspecialchars($mainClass, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($isLoggedIn): ?>
            <div class="pf-shellbar">
                <div class="pf-shellbar-left">
                    <a href="/dashboard" class="pf-brandlink" aria-label="Plainfully dashboard">
                        <picture>
                            <source srcset="/assets/img/pf_logo_dark.png" media="(prefers-color-scheme: dark)">
                            <img src="/assets/img/pf_logo_light.png" alt="Plainfully" class="pf-logo">
                        </picture>
                        <span class="pf-brandword">Plainfully</span>
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
