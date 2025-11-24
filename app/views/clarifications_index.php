<?php
/** @var array $clarifications */
/** @var string|null $flashOk */
/** @var string|null $flashError */
?>
<h1 class="pf-page-title">Your clarifications</h1>

<p class="pf-page-subtitle">
    These are the requests you’ve asked Plainfully to clarify.
</p>

<?php if (!empty($flashOk)): ?>
    <p class="pf-message-ok">
        <?= htmlspecialchars($flashOk, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<?php if (!empty($flashError)): ?>
    <p class="pf-message-error">
        <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<div class="pf-list-actions">
    <a href="/clarifications/new" class="pf-button">
        New clarification
    </a>
</div>

<?php if (empty($clarifications)): ?>
    <p>You don’t have any clarification requests yet.</p>
<?php else: ?>
    <table class="pf-table">
        <thead>
        <tr>
            <th>Created</th>
            <th>Title</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($clarifications as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?= htmlspecialchars($row['title'] ?? '(Untitled)', ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
