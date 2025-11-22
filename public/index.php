<?php declare(strict_types=1);
session_start();
// Load .env (simple loader)
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        putenv("$k=$v");
    }
}

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
            <h1 class="pf-auth-title">You are logged in</h1>
            <p class="pf-auth-subtitle">
                User ID: <?= (int)$userId ?> (placeholder; we’ll replace this with a real dashboard later).
            </p>
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
