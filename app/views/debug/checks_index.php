<?php declare(strict_types=1); ?>

<style>
    /* For debug pages, let the main shell go full width */
    .pf-shell-main,
    .pf-main-inner {
        max-width: 100% !important;
        width: 100% !important;
    }

    /* Keep content centred but wide */
    .pf-debug-wrapper {
        max-width: 1800px;
        margin: 0 auto;
        padding: 32px 24px;
    }

    /* Table already uses width:100% – this just ensures it fills the area */
    .pf-debug-table-container {
        width: 100%;
    }
</style>

<div class="pf-debug-wrapper">

    <div class="pf-debug-header">
        <h1>Debug – Recent checks</h1>
        <p class="pf-debug-sub">
            Latest 50 entries from the <strong>checks</strong> table (no raw content stored).
        </p>
    </div>

    <div class="pf-debug-table-container">
        <table class="pf-debug-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Channel</th>
                    <th>Source</th>
                    <th>Is scam?</th>
                    <th>Paid?</th>
                    <th>Short summary</th>
                    <th>Created</th>
                    <th>View</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars((string)$r['channel'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string)$r['source_identifier'], ENT_QUOTES, 'UTF-8') ?></td>

                        <td>
                            <?php if ((int)$r['is_scam'] === 1): ?>
                                <span class="pf-flag pf-flag-red">Yes</span>
                            <?php else: ?>
                                <span class="pf-flag pf-flag-green">No</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ((int)$r['is_paid'] === 1): ?>
                                <span class="pf-flag pf-flag-blue">Yes</span>
                            <?php else: ?>
                                <span class="pf-flag pf-flag-grey">No</span>
                            <?php endif; ?>
                        </td>

                        <td class="pf-debug-summary">
                            <?= htmlspecialchars((string)$r['short_summary'], ENT_QUOTES, 'UTF-8') ?>
                        </td>

                        <td><?= htmlspecialchars((string)$r['created_at'], ENT_QUOTES, 'UTF-8') ?></td>

                        <td>
                            <a class="pf-debug-view" href="/debug/checks/view?id=<?= (int)$r['id'] ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

<style>


.pf-debug-wrapper {
    padding: 32px 20px;
}

.pf-debug-header h1 {
    color: var(--pf-text-main);
    font-size: 26px;
    margin-bottom: 4px;
}

.pf-debug-sub {
    color: var(--pf-text-muted);
    margin-bottom: 24px;
}

.pf-debug-table-container {
    overflow-x: auto;
}

.pf-debug-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--pf-surface);
    border: 1px solid var(--pf-border-subtle);
    border-radius: 12px;
    overflow: hidden;
}

.pf-debug-table th {
    text-align: left;
    padding: 12px;
    background: var(--pf-surface-soft);
    color: var(--pf-text-muted);
    font-weight: 600;
    font-size: 14px;
}

.pf-debug-table td {
    padding: 12px;
    border-bottom: 1px solid var(--pf-border-subtle);
    color: var(--pf-text-main);
    font-size: 14px;
}

.pf-debug-summary {
    max-width: 260px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.pf-debug-view {
    color: var(--pf-accent);
    text-decoration: none;
    font-weight: 500;
}

.pf-debug-view:hover {
    text-decoration: underline;
}

.pf-flag {
    padding: 3px 8px;
    font-size: 12px;
    border-radius: 6px;
    font-weight: 600;
}
.pf-flag-green { background: #1AB38533; color: #1AB385; }
.pf-flag-red   { background: #C3214833; color: #C32148; }
.pf-flag-blue  { background: #3fa0ff33; color: #3fa0ff; }
.pf-flag-grey  { background: #55555533; color: #b5b5b5; }
</style>
