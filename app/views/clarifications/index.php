<?php
/** @var array $rows */
?>
<h1 class="pf-page-title">Your clarifications</h1>
<p class="pf-page-subtitle">
    These are the recent checks you’ve asked Plainfully to run.
</p>

<div class="pf-table-wrapper">
    <table class="pf-table">
        <thead>
        <tr>
            <th>ID</th>
            <th>Channel</th>
            <th>Summary</th>
            <th>Scam?</th>
            <th>Paid?</th>
            <th>Created</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars((string)$r['channel'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars((string)$r['short_summary'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?= ((int)$r['is_scam'] === 1) ? 'Yes' : 'No'; ?>
                </td>
                <td>
                    <?= ((int)$r['is_paid'] === 1) ? 'Yes' : 'No'; ?>
                </td>
                <td><?= htmlspecialchars((string)$r['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><a href="/clarifications/view?id=<?= (int)$r['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
