<?php declare(strict_types=1); ?>
<?php
/**
 * Debug – Recent checks
 *
 * Expects:
 *   $rows = array of checks from controller
 */
?>

<style>
    /* ===== Shell overrides – debug pages only =====
       We aggressively remove the narrow "card" layout so the page
       can use the full browser width. This CSS is only loaded on
       /debug/checks so it won't affect the rest of the site.
    */

    html, body {
        width: 100%;
    }

    /* All top-level layout wrappers go full width */
    body > div {
        max-width: none !important;
        width: 100% !important;
    }

    /* Common shell classes from the main layout (belt + braces) */
    .pf-shell-main,
    .pf-main-inner,
    .pf-shell-card,
    .pf-shell-surface {
        max-width: none !important;
        width: 100% !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        padding-left: 0 !important;
        padding-right: 0 !important;
    }

    /* ===== Debug page layout ===== */

    .pf-debug-page {
        max-width: 1400px;          /* adjust if you want wider/narrower */
        margin: 0 auto;
        padding: 32px 32px 40px 32px;
    }

    .pf-debug-header h1 {
        color: var(--pf-text-main);
        font-size: 28px;
        margin: 0 0 6px 0;
    }

    .pf-debug-header p {
        color: var(--pf-text-muted);
        margin: 0 0 24px 0;
    }

    /* Table container with sticky header support on scroll */
    .pf-debug-table-container {
        width: 100%;
        border-radius: 18px;
        overflow: hidden;
        border: 1px solid var(--pf-border-subtle);
        background: var(--pf-surface);
        box-shadow: 0 18px 40px rgba(0,0,0,0.35);
    }

    .pf-debug-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: 14px;
    }

    .pf-debug-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: var(--pf-surface-soft);
        color: var(--pf-text-muted);
        text-align: left;
        padding: 10px 12px;
        font-weight: 600;
        border-bottom: 1px solid var(--pf-border-subtle);
    }

    .pf-debug-table tbody tr:nth-child(even) {
        background: rgba(255,255,255,0.01);
    }

    .pf-debug-table tbody tr:hover {
        background: rgba(255,255,255,0.03);
    }

    .pf-debug-table td {
        padding: 10px 12px;
        border-bottom: 1px solid var(--pf-border-subtle);
        color: var(--pf-text-main);
        vertical-align: middle;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .pf-debug-col-id       { width: 60px; }
    .pf-debug-col-channel  { width: 80px; }
    .pf-debug-col-source   { width: 190px; }
    .pf-debug-col-flag     { width: 90px; }
    .pf-debug-col-paid     { width: 90px; }
    .pf-debug-col-created  { width: 160px; }
    .pf-debug-col-view     { width: 70px; }
    .pf-debug-col-summary  { width: auto; }

    .pf-debug-summary {
        max-width: 100%;
    }

    .pf-debug-view-link {
        color: var(--pf-accent);
        text-decoration: none;
        font-weight: 500;
    }
    .pf-debug-view-link:hover {
        text-decoration: underline;
    }

    /* Status "pill" flags */
    .pf-flag {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 42px;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 600;
    }

    .pf-flag-green { background: #1AB38526; color: #1AB385; }
    .pf-flag-red   { background: #C3214826; color: #C32148; }
    .pf-flag-blue  { background: #3fa0ff26; color: #3fa0ff; }
    .pf-flag-grey  { background: #55555526; color: #b5b5b5; }

    @media (max-width: 900px) {
        .pf-debug-page {
            padding: 16px;
        }
        .pf-debug-table-container {
            border-radius: 0;
        }
        .pf-debug-table thead th,
        .pf-debug-table td {
            padding: 8px;
        }
    }
</style>

<div class="pf-debug-page">

    <div class="pf-debug-header">
        <h1>Debug – Recent checks</h1>
        <p>
            Latest 50 entries from the <strong>checks</strong> table (no raw content stored).
        </p>
    </div>

    <div class="pf-debug-table-container">
        <table class="pf-debug-table">
            <thead>
                <tr>
                    <th class="pf-debug-col-id">ID</th>
                    <th class="pf-debug-col-channel">Channel</th>
                    <th class="pf-debug-col-source">Source</th>
                    <th class="pf-debug-col-flag">Is scam?</th>
                    <th class="pf-debug-col-paid">Paid?</th>
                    <th class="pf-debug-col-summary">Short summary</th>
                    <th class="pf-debug-col-created">Created</th>
                    <th class="pf-debug-col-view">View</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="pf-debug-col-id"><?= (int)$r['id'] ?></td>
                    <td class="pf-debug-col-channel">
                        <?= htmlspecialchars((string)$r['channel'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="pf-debug-col-source">
                        <?= htmlspecialchars((string)$r['source_identifier'], ENT_QUOTES, 'UTF-8') ?>
                    </td>

                    <td class="pf-debug-col-flag">
                        <?php if ((int)$r['is_scam'] === 1): ?>
                            <span class="pf-flag pf-flag-red">Yes</span>
                        <?php else: ?>
                            <span class="pf-flag pf-flag-green">No</span>
                        <?php endif; ?>
                    </td>

                    <td class="pf-debug-col-paid">
                        <?php if ((int)$r['is_paid'] === 1): ?>
                            <span class="pf-flag pf-flag-blue">Yes</span>
                        <?php else: ?>
                            <span class="pf-flag pf-flag-grey">No</span>
                        <?php endif; ?>
                    </td>

                    <td class="pf-debug-col-summary pf-debug-summary">
                        <?= htmlspecialchars((string)$r['short_summary'], ENT_QUOTES, 'UTF-8') ?>
                    </td>

                    <td class="pf-debug-col-created">
                        <?= htmlspecialchars((string)$r['created_at'], ENT_QUOTES, 'UTF-8') ?>
                    </td>

                    <td class="pf-debug-col-view">
                        <a class="pf-debug-view-link"
                           href="/debug/checks/view?id=<?= (int)$r['id'] ?>">
                            View
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>
