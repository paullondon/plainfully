<?php
// View: login form inner HTML
?>
<?php
// login.php - visual login page wrapper
$siteKey = $config['turnstile.site_key'] ?? '';
$loginError = $_SESSION['magic_link_error'] ?? '';
unset($_SESSION['magic_link_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Plainfully</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="pf-shell">
<main class="pf-page-center">
    <section class="pf-auth-card">

        <!-- HEADER: logo + text inline -->
        <header class="pf-auth-header">
            <div class="pf-logo">
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

            <div class="cf-turnstile"
                data-sitekey="<?= htmlspecialchars((string)$siteKey, ENT_QUOTES, 'UTF-8') ?>"
                data-callback="pfOnTurnstileSuccess"
                data-size="invisible"
                data-action="magic_link">
            </div>

            <button type="submit" class="pf-button">
                Send magic link
            </button>
        </form>

        <p class="pf-note">
            This link expires in about 30 minutes and can only be used once.
        </p>

        <?php if (!empty($loginOk)): ?>
            <p class="pf-message-ok">
                <?= htmlspecialchars($loginOk, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($loginError)): ?>
            <p class="pf-message-error">
                <?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
        
    </section>
</main>

<script>
    function pfOnTurnstileSuccess() {
        const form = document.getElementById('magic-link-form');
        if (form && !form.dataset.submitted) {
            form.dataset.submitted = '1';
            form.submit();
        }
    }
</script>
</body>
</html>