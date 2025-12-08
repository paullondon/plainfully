<?php
/** @var array $checks */
?>
<h1 class="pf-page-title">Your clarifications</h1>

<p class="pf-page-subtitle">
    These are the messages you’ve asked Plainfully to check. We never store the full text – only safe summaries.
</p>

<?php if (empty($checks)): ?>
    <p>You haven’t run any clarifications yet.</p>
    <p><a href="/clarifications/new" class="pf-button">Start a clarification</a></p>
<?php else: ?>
    <table class="pf-table">
        <thead>
            <tr>
                <th>When</th>
                <th>Channel</th>
                <th>Summary</th>
                <th>Scam?</th>
                <th>Plan</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($checks as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['channel'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <a href="/clarifications/view?id=<?= (int)$row['id'] ?>">
                        <?= htmlspecialchars($row['short_summary'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </td>
                <td>
                    <?php if ((int)$row['is_scam'] === 1): ?>
                        <span class="pf-pill pf-pill-danger">Likely scam</span>
                    <?php else: ?>
                        <span class="pf-pill pf-pill-ok">No obvious scam</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ((int)$row['is_paid'] === 1): ?>
                        <span class="pf-pill pf-pill-ok">Paid</span>
                    <?php else: ?>
                        <span class="pf-pill pf-pill-muted">Free</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top:1.5rem;">
        <a href="/clarifications/new" class="pf-button">Run another clarification</a>
    </p>
<?php endif; ?>
