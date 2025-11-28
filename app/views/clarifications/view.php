<?php declare(strict_types=1);

require_once __DIR__ . '/../../support/clarifications.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$id = $_GET['id'] ?? null;
$id = is_numeric($id) ? (int)$id : null;
$userId = plainfully_current_user_id();

if ($userId === null) {
    http_response_code(403);
    echo 'You must be signed in to view this clarification.';
    return;
}

if ($id === null || $id <= 0) {
    http_response_code(404);
    echo 'Clarification not found.';
    return;
}

$pdo = plainfully_pdo();

// Load main clarification
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
    http_response_code(404);
    echo 'Clarification not found.';
    return;
}

// Load first detail row
$stmt = $pdo->prepare("
    SELECT prompt_ciphertext, model_response_ciphertext
    FROM clarification_details
    WHERE clarification_id = :id
    ORDER BY sequence_no ASC
    LIMIT 1
");
$stmt->bindValue(':id', $id, \PDO::PARAM_INT);
$stmt->execute();
$detail = $stmt->fetch();

$originalText = '';
$clarifiedText = '';

if ($detail) {
    $originalText   = plainfully_decrypt((string)$detail['prompt_ciphertext']);
    $clarifiedText  = plainfully_decrypt((string)$detail['model_response_ciphertext']);
}
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
        <h1 class="pf-heading">Your clarification</h1>

        <p class="pf-meta">
            Created: <?= htmlspecialchars($clar['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            &nbsp;•&nbsp;
            Tone: <?= htmlspecialchars($clar['tone'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </p>

        <div class="pf-field">
            <h2 class="pf-label">Plainfully’s version</h2>
            <div class="pf-box">
                <pre><?= htmlspecialchars($clarifiedText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
            </div>
        </div>

        <div class="pf-actions">
            <a href="/clarifications/new" class="pf-button pf-button--secondary">
                Start another clarification
            </a>
        </div>
    </section>
</main>
</body>
</html>
