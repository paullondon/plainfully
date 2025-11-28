<?php declare(strict_types=1);

require_once __DIR__ . '/../../support/clarifications.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id = $_GET['id'] ?? null;
$id = is_numeric($id) ? (int)$id : null;

if ($id === null || $id <= 0) {
    header('Location: /dashboard', true, 302);
    exit;
}

$pdo    = plainfully_pdo();
$userId = plainfully_current_user_id();

if ($userId === null) {
    header('Location: /auth/login', true, 302);
    exit;
}

// Load clarification, enforcing ownership
$stmt = $pdo->prepare("
    SELECT id, user_id, status, source, tone, created_at
    FROM clarifications
    WHERE id = :id
      AND user_id = :user_id
    LIMIT 1
");
$stmt->bindValue(':id', $id, \PDO::PARAM_INT);
$stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
$stmt->execute();
$clar = $stmt->fetch();

if (!$clar) {
    // Not found or not owned by this user → push back to dashboard
    header('Location: /dashboard', true, 302);
    exit;
}

// Load first detail row
$stmt = $pdo->prepare("
    SELECT model_response_ciphertext
    FROM clarification_details
    WHERE clarification_id = :id
    ORDER BY sequence_no ASC
    LIMIT 1
");
$stmt->bindValue(':id', $id, \PDO::PARAM_INT);
$stmt->execute();
$detail = $stmt->fetch();

$clarifiedText = '';

if ($detail) {
    $clarifiedText = plainfully_decrypt((string)$detail['model_response_ciphertext']);
}

// Determine if this can be "cancelled" (treated as abandoned)
// e.g. only within 5 minutes of creation
$allowCancel = false;
try {
    if (!empty($clar['created_at'])) {
        $createdAt   = new DateTimeImmutable($clar['created_at'], new DateTimeZone('UTC'));
        $fiveMinutes = new DateTimeImmutable('-5 minutes', new DateTimeZone('UTC'));
        $allowCancel = $createdAt >= $fiveMinutes;
    }
} catch (Throwable $e) {
    $allowCancel = false;
}

$csrfToken = plainfully_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your Clarification | Plainfully</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="pf-page-body">
<main class="pf-page-main">
    <section class="pf-card pf-card--narrow">
        <h1 class="pf-heading">Your clarification is ready</h1>

        <p class="pf-meta">
            Tone: <?= htmlspecialchars($clar['tone'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            &nbsp;•&nbsp;
            Created: <?= htmlspecialchars($clar['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </p>

        <!-- Upsell banner -->
        <div class="pf-upsell">
            <strong>Plainfully Free</strong> keeps your clarifications for 28 days.
            Want longer history and priority processing?
            <a href="/plans">See plans</a>.
        </div>

        <div class="pf-field">
            <h2 class="pf-label">Plainfully’s version</h2>
            <div class="pf-box">
                <pre><?= htmlspecialchars($clarifiedText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
            </div>
        </div>

        <div class="pf-actions pf-actions--split">
            <a href="/dashboard" class="pf-button pf-button--ghost">
                Back to your dashboard
            </a>
            <a href="/clarifications/new" class="pf-button pf-button--primary">
                Start another clarification
            </a>
        </div>

        <?php if ($allowCancel): ?>
            <form method="post"
                  action="/clarifications/cancel"
                  class="pf-actions pf-actions--inline-danger">
                <input type="hidden" name="_token"
                       value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="id"
                       value="<?= htmlspecialchars((string)$clar['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <button type="submit" class="pf-button pf-button--danger-ghost">
                    Cancel and remove this clarification
                </button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
