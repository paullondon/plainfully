<?php declare(strict_types=1); ?>

<style>
main,
.pf-shell-main,
.pf-main-inner,
.pf-debug-wrapper {
    max-width: 1200px;
    width: 100%;
    margin: 0 auto;
}
</style>

<div class="pf-debug-wrapper">
    <div class="pf-debug-header">
        <h1>Debug – Check #<?= (int)$row['id'] ?></h1>
        <p class="pf-debug-sub">
            Inspect the stored summary and AI result JSON for this check.
        </p>
    </div>

    <div class="pf-debug-meta">
        <dl>
            <dt>Channel</dt>
            <dd><?= htmlspecialchars((string)$row['channel'], ENT_QUOTES, 'UTF-8') ?></dd>

            <dt>Source identifier</dt>
            <dd><?= htmlspecialchars((string)$row['source_identifier'], ENT_QUOTES, 'UTF-8') ?></dd>

            <dt>Is scam?</dt>
            <dd>
                <?php if ((int)$row['is_scam'] === 1): ?>
                    <span class="pf-flag pf-flag-red">Yes</span>
                <?php else: ?>
                    <span class="pf-flag pf-flag-green">No</span>
                <?php endif; ?>
            </dd>

            <dt>Paid?</dt>
            <dd>
                <?php if ((int)$row['is_paid'] === 1): ?>
                    <span class="pf-flag pf-flag-blue">Yes</span>
                <?php else: ?>
                    <span class="pf-flag pf-flag-grey">No</span>
                <?php endif; ?>
            </dd>

            <dt>Short summary</dt>
            <dd><?= nl2br(htmlspecialchars((string)$row['short_summary'], ENT_QUOTES, 'UTF-8')) ?></dd>

            <dt>Created</dt>
            <dd><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></dd>

            <dt>Updated</dt>
            <dd><?= htmlspecialchars((string)$row['updated_at'], ENT_QUOTES, 'UTF-8') ?></dd>
        </dl>
    </div>

    <h2 class="pf-debug-sub">AI result JSON</h2>

    <pre class="pf-debug-pre"><?= htmlspecialchars($aiJsonPretty, ENT_QUOTES, 'UTF-8') ?></pre>

    <p class="pf-debug-back">
        <a href="/debug/checks" class="pf-debug-view">← Back to checks list</a>
    </p>

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
    margin-bottom: 16px;
}

.pf-debug-meta dl {
    display: grid;
    grid-template-columns: 160px 1fr;
    row-gap: 6px;
    column-gap: 12px;
    margin-bottom: 24px;
}

.pf-debug-meta dt {
    color: var(--pf-text-soft);
    font-weight: 500;
}

.pf-debug-meta dd {
    margin: 0;
    color: var(--pf-text-main);
}

.pf-debug-pre {
    background: var(--pf-surface);
    border: 1px solid var(--pf-border-subtle);
    border-radius: 10px;
    padding: 12px;
    font-size: 13px;
    max-height: 420px;
    overflow: auto;
    white-space: pre;
}

.pf-debug-back {
    margin-top: 18px;
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
