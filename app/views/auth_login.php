<?php
// app/views/auth_login.php

$siteKey    = $siteKey    ?? '';
$loginError = $loginError ?? '';
$loginOk    = $loginOk    ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Plainfully</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/app.css">
            <style>
        .pf-auth-card {
            max-width: 480px;
            width: 100%;
            padding: 2.5rem;
            border-radius: 20px;
            background: #1f1f1f;
            border: 1px solid #2b2b2b;
            box-shadow: 0 10px 24px rgba(0,0,0,0.35);
        }

        .pf-auth-header {
            display: flex;
            align-items: center;
            gap: 1.4rem;
            margin-bottom: 1.75rem;
        }

        .pf-logo {
            width: 80px;
            height: 80px;
            flex-shrink: 0;
        }

        .pf-logo-img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: contain;
        }

        .pf-auth-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0 0 .4rem;
        }

        .pf-auth-subtitle {
            margin: 0;
            font-size: .95rem;
            color: #b5b5b5;
        }

        .pf-field {
            margin-top: 1.75rem;
            margin-bottom: 1.25rem;
        }

        .pf-label {
            display: block;
            margin-bottom: .4rem;
            font-size: .9rem;
            color: #b5b5b5;
        }

        .pf-input {
            width: 100%;
            padding: .75rem 1rem;
            border-radius: 10px;
            border: 1px solid #2b2b2b;
            background: #111;
            color: #E8E8E8;
            font-size: 1rem;
            box-sizing: border-box;
        }

        .pf-input::placeholder {
            color: #555;
        }

        .pf-input:focus {
            outline: none;
            border-color: #1AB385;
            box-shadow: 0 0 0 2px rgba(26,179,133,0.16);
        }

        .pf-button {
            width: 100%;
            background: #1AB385;
            color: #05150f;
            border: none;
            padding: .9rem 1.2rem;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            margin-top: .5rem;
            cursor: pointer;
            transition: background 0.18s ease, transform 0.1s ease;
        }

        .pf-button:hover {
            background: #0C6349;
            transform: translateY(-1px);
        }

        .pf-button:active {
            transform: translateY(0);
        }

        .pf-note {
            margin-top: 1rem;
            font-size: .85rem;
            color: #b5b5b5;
        }
    </style>

</head>
<body class="pf-shell">
<main class="pf-page-center">
    <section class="pf-auth-card">

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
