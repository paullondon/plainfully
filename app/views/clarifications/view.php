<?php
/** @var array $row */
$status = htmlspecialchars($row['status'], ENT_QUOTES);
?>

<h1 class="pf-page-title"><?= htmlspecialchars($row['title'], ENT_QUOTES) ?></h1>

<div class="pf-card">
    <h2 class="pf-card-title">Original Text</h2>
    <p class="pf-body"><?= nl2br(htmlspecialchars($row['original_text'], ENT_QUOTES)) ?></p>
</div>

<div class="pf-card">
    <h2 class="pf-card-title">Status</h2>
    <p class="pf-status pf-status-<?= $status ?>">
        <?= ucfirst($status) ?>
    </p>
</div>

<?php if ($row['status'] === 'completed'): ?>
    <div class="pf-card">
        <h2 class="pf-card-title">Clarified Meaning</h2>
        <p class="pf-body"><?= nl2br(htmlspecialchars($row['clarified_text'], ENT_QUOTES)) ?></p>
    </div>
<?php else: ?>
    <div class="pf-card">
        <h2 class="pf-card-title">Clarified Meaning</h2>
        <p class="pf-body pf-placeholder">Still processing…</p>
    </div>
<?php endif; ?>
