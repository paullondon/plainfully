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
    <link rel="stylesheet"
          href="/assets/css/app.css?v=<?= $cssVersion ?>">
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
                    src="/assets/img/logo-icon.svg"
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

            <?php if (!empty($siteKey)): ?>
                <div class="pf-field">
                    <label class="pf-label">Security check</label>

                    <!-- Turnstile container (we render into this via JS) -->
                    <div id="pf-turnstile-container"></div>

                    <!-- Hidden field we explicitly populate with the token -->
                    <input type="hidden"
                           name="cf-turnstile-response"
                           id="pf-turnstile-token"
                           value="">
                </div>
            <?php else: ?>
                <p class="pf-note">
                    <strong>Warning:</strong> Turnstile site key is not configured.
                </p>
            <?php endif; ?>

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

<?php if (!empty($siteKey)): ?>
    <!-- Cloudflare Turnstile API -->
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

    <!-- Explicit render + execute for invisible Turnstile -->
    <script>
        (function () {
            var widgetId = null;

            function initTurnstile() {
                var container = document.getElementById('pf-turnstile-container');
                if (!container || typeof turnstile === 'undefined') {
                    console.error('Turnstile JS not loaded or container missing');
                    return;
                }

                widgetId = turnstile.render('#pf-turnstile-container', {
                    sitekey: <?= json_encode($siteKey, JSON_UNESCAPED_SLASHES) ?>,
                    size: 'invisible',
                    action: 'magic_link',
                    callback: pfOnTurnstileSuccess
                });
            }

            // Intercept form submit and run Turnstile first
            document.addEventListener('DOMContentLoaded', function () {
                var form = document.getElementById('magic-link-form');
                if (!form) {
                    return;
                }

                form.addEventListener('submit', function (e) {
                    // If we've already passed Turnstile once, allow normal submit
                    if (form.dataset.turnstileOk === '1') {
                        return;
                    }

                    e.preventDefault();

                    if (typeof turnstile === 'undefined') {
                        console.error('Turnstile JS not available, submitting anyway (dev fallback)');
                        form.submit();
                        return;
                    }

                    if (widgetId === null) {
                        initTurnstile();
                    }

                    try {
                        if (widgetId !== null) {
                            turnstile.execute(widgetId);
                        } else {
                            // Fallback if something went weird, avoid locking user out
                            form.submit();
                        }
                    } catch (err) {
                        console.error('Error executing Turnstile:', err);
                        form.submit();
                    }
                });

                // Initialise as soon as DOM + Turnstile script are ready
                if (typeof turnstile !== 'undefined') {
                    initTurnstile();
                } else {
                    // In case script loads slightly after DOMContentLoaded
                    var checkCount = 0;
                    var intervalId = setInterval(function () {
                        if (typeof turnstile !== 'undefined') {
                            clearInterval(intervalId);
                            initTurnstile();
                        } else if (++checkCount > 20) {
                            clearInterval(intervalId);
                        }
                    }, 250);
                }
            });

            // Global callback used by Turnstile (must be global)
            window.pfOnTurnstileSuccess = function (token) {
                var form   = document.getElementById('magic-link-form');
                var hidden = document.getElementById('pf-turnstile-token');

                if (hidden) {
                    hidden.value = token || '';
                }

                if (form) {
                    form.dataset.turnstileOk = '1';
                    form.submit();
                }
            };
        })();
    </script>
<?php endif; ?>
</body>
</html>
