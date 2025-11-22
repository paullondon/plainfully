<?php declare(strict_types=1);
session_start();

$login_error = $_SESSION['magic_link_error']  ?? '';
$login_ok    = $_SESSION['magic_link_ok']     ?? '';
unset($_SESSION['magic_link_error'], $_SESSION['magic_link_ok']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login | Plainfully</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<main class="pf-auth-shell">
    <section class="pf-auth-card">
        <h1 class="pf-auth-title">Sign in to Plainfully</h1>
        <p class="pf-auth-subtitle">
            We’ll email you a one-time magic link to sign in.
        </p>

        <form method="post" action="/request_magic_link.php" novalidate>
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

        <p class="pf-note">
            For security, the link will expire in about 30 minutes and can only be used once.
        </p>

        <?php if ($login_ok): ?>
            <p class="pf-message-ok"><?= htmlspecialchars($login_ok, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if ($login_error): ?>
            <p class="pf-message-error"><?= htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
