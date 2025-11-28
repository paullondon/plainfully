<?php declare(strict_types=1);

require_once __DIR__ . '/../../support/clarifications.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dashboard', true, 302);
    exit;
}

$idRaw = $_POST['id'] ?? null;
$id    = is_numeric($idRaw) ? (int)$idRaw : null;

if (!plainfully_verify_csrf_token($_POST['_token'] ?? null) || $id === null || $id <= 0) {
    header('Location: /dashboard', true, 302);
    exit;
}

$pdo    = plainfully_pdo();
$userId = plainfully_current_user_id();

if ($userId === null) {
    header('Location: /auth/login', true, 302);
    exit;
}

// Confirm it belongs to the user and is still "recent" enough to treat as abandon
$stmt = $pdo->prepare("
    SELECT id, user_id, created_at
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
    header('Location: /dashboard', true, 302);
    exit;
}

// Optional extra: only allow cancellation within 5 minutes of creation (same rule as view)
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

if (!$allowCancel) {
    // Treat as "real" now – don't delete, just go back.
    header('Location: /dashboard', true, 302);
    exit;
}

try {
    $pdo->beginTransaction();

    // Delete detail rows first (if you don't have ON DELETE CASCADE)
    $delDetails = $pdo->prepare("
        DELETE FROM clarification_details
        WHERE clarification_id = :id
    ");
    $delDetails->bindValue(':id', $id, \PDO::PARAM_INT);
    $delDetails->execute();

    // Delete main clarification
    $delClar = $pdo->prepare("
        DELETE FROM clarifications
        WHERE id = :id
          AND user_id = :user_id
    ");
    $delClar->bindValue(':id', $id, \PDO::PARAM_INT);
    $delClar->bindValue(':user_id', $userId, \PDO::PARAM_INT);
    $delClar->execute();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[Plainfully] Failed to cancel clarification ID ' . $id . ': ' . $e->getMessage());
}

// After cancel, it is "invisible" to the user
header('Location: /dashboard', true, 302);
exit;
