<?php declare(strict_types=1);
session_start();

$userId = $_SESSION['user_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Plainfully</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<main class="pf-auth-shell">
    <section class="pf-auth-card">
        <?php if ($userId): ?>
            <h1 class="pf-auth-title">Plainfully</h1>
            <p class="pf-auth-subtitle">
                You’re signed in. (User ID: <?= (int)$userId ?>)
            </p>

            <form method="post" action="/logout.php">
                <button type="submit" class="pf-button">
                    Sign out
                </button>
            </form>
        <?php else: ?>
            <h1 class="pf-auth-title">Welcome to Plainfully</h1>
            <p class="pf-auth-subtitle">
                You’re not logged in yet. Use a magic link to sign in.
            </p>
            <a class="pf-button" href="/login.php">Go to login</a>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
