<?php declare(strict_types=1);

function handle_login_form(array $config): void
{
    $siteKey = $config['security']['turnstile_site_key'] ?? '';

    $loginError = $_SESSION['magic_link_error']  ?? '';
    $loginOk    = $_SESSION['magic_link_ok']     ?? '';

    unset($_SESSION['magic_link_error'], $_SESSION['magic_link_ok']);

    ob_start();
    ?>
    <h1 class="pf-auth-title">Sign in to Plainfully</h1>
    <p class="pf-auth-subtitle">
        We’ll email you a one-time magic link to sign in.
    </p>

    <form method="post"
          action="/magic/request"
          novalidate
          id="magic-link-form">

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

        <button type="submit" class="pf-button">Send magic link</button>
    </form>

    <p class="pf-note">This link expires in 30 minutes and can only be used once.</p>

    <?php if ($loginOk): ?>
        <p class="pf-message-ok"><?= htmlspecialchars($loginOk) ?></p>
    <?php endif; ?>

    <?php if ($loginError): ?>
        <p class="pf-message-error"><?= htmlspecialchars($loginError) ?></p>
    <?php endif; ?>

    <script>
        function pfOnTurnstileSuccess() {
            const form = document.getElementById('magic-link-form');
            if (form && !form.dataset.submitted) {
                form.dataset.submitted = '1';
                form.submit();
            }
        }
    </script>

    <?php
    $inner = ob_get_clean();
    pf_render_shell('Login | Plainfully', $inner);
}
