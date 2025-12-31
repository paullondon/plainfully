<?php
// app/views/auth_login.php

/** @var array  $config */
/** @var string $siteKey */
/** @var string $loginError */
/** @var string $loginOk */

$siteKey    = $siteKey    ?? '';
$loginError = $loginError ?? '';
$loginOk    = $loginOk    ?? '';

$cssVersion = htmlspecialchars((string)($config['css'] ?? '1'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Login | Plainfully</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Main stylesheet with versioning taken from config -->
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= $cssVersion ?>">
</head>
<body class="pf-shell">
<main class="pf-page-center">
    <section class="pf-auth-card">
        <?php if (($_GET['session'] ?? '') === 'expired'): ?>
            <div class="pf-alert pf-alert--debug">
                You were signed out because you were inactive for a while. Please sign in again.
            </div>
        <?php endif; ?>

        <!-- HEADER: logo + heading inline -->
        <header class="pf-auth-header">
            <div class="pf-logo pf-logo--large">
                <img
                    src="/assets/img/plainfully-logo-bimi.svg"
                    alt="Plainfully logo"
                    class="pf-logo-img">
            </div>

            <div class="pf-auth-heading">
                <h1 class="pf-auth-title">Sign in to Plainfully</h1>
                <p class="pf-auth-subtitle">
                    We’ll email you a one-time magic link to sign in.
                </p>
            </div>
        </header>

        <!-- FORM -->
        <form method="post"
              action="/login"
              novalidate
              id="magic-link-form">

            <?php pf_csrf_field(); ?>

            <div class="pf-field">
                <label for="email" class="pf-label">Email address</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    required
                    autocomplete="email"
                    class="pf-input"
                    placeholder="you@example.com">
            </div>

            <button type="submit" class="pf-button">
                Send magic link
            </button>
        </form>

        <!-- NOTE -->
        <p class="pf-note">
            This link expires in about 30 minutes and can only be used once.
        </p>

        <!-- MESSAGES -->
        <?php if (!empty($loginOk)): ?>
            <p class="pf-message-ok">
                <?= htmlspecialchars($loginOk, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
        
        <?php
        global $config;

        $debugMagicLinksEnabled = !empty($config['debug']['magic_links'] ?? false);
        $debugUrl = $_SESSION['magic_link_debug_url'] ?? null;

        if ($debugMagicLinksEnabled && $debugUrl): ?>
            <div class="pf-alert pf-alert--debug" style="margin-top: 0.5rem;">
                <strong>DEBUG:</strong>
                Direct sign-in link for this browser:
                <br>
                <a href="<?= htmlspecialchars($debugUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    Click here to sign in without email
                </a>
            </div>
        <?php
            // one-time use
            unset($_SESSION['magic_link_debug_url']);
        endif;
        ?>

        <?php if (!empty($_SESSION['debug_logout_reason'])): ?>
            <p style="color:#ffb4a9;font-size:.8rem;margin-top:.5rem;">
                DEBUG logout reason: <?= htmlspecialchars($_SESSION['debug_logout_reason'], ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php unset($_SESSION['debug_logout_reason']); ?>
        <?php endif; ?>

        <?php if (!empty($loginError)): ?>
            <p class="pf-message-error">
                <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

    </section>
</main>
</body>
</html>
