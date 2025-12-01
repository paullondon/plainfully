<?php
/** @var array $clar */
/** @var bool  $isCompleted */
/** @var bool  $isCancellable */
/** @var string $pageTitle */

$id          = (int)$clar['id'];
$tone        = $clar['tone'] ?? 'notapplicable';
$status      = $clar['status'] ?? 'completed';
$resultText  = $clar['result_text'] ?? '';
$createdAt   = $clar['created_at'] ?? null;
$completedAt = $clar['completed_at'] ?? null;

// Format dates very simply; adjust to taste or use a helper later
function pf_fmt_dt(?string $dt): string {
    if (!$dt) {
        return '';
    }
    try {
        $dtObj = new DateTimeImmutable($dt);
        return $dtObj->format('j M Y, H:i');
    } catch (Throwable $e) {
        return $dt;
    }
}
?>
<section class="pf-card pf-card--narrow">
    <header style="margin-bottom:1.1rem;">
        <h1 class="pf-page-title">Your clarification</h1>
        <p class="pf-page-subtitle">
            This is Plainfully’s rephrased version of the message you asked about.
            We never show the original text here for your privacy.
        </p>
    </header>

    <!-- Meta bar -->
    <div class="pf-meta" style="
        display:flex;
        flex-wrap:wrap;
        gap:0.75rem;
        margin-bottom:1rem;
        font-size:0.85rem;
    ">
        <span>Clarification #<?= htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($createdAt): ?>
            <span>• Started: <?= htmlspecialchars(pf_fmt_dt($createdAt), ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <?php if ($completedAt): ?>
            <span>• Completed: <?= htmlspecialchars(pf_fmt_dt($completedAt), ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <span>• Status: <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <!-- Plan upsell -->
    <div class="pf-upsell">
        <strong>Plainfully Basic</strong> gives you a simple, secure way to clarify tricky messages.
        In the future you’ll be able to upgrade for richer history, sharing tools and additional tones.
        <br>
        <span style="font-size:0.8rem;color:var(--pf-text-muted);">
            For now, enjoy unlimited clarifications while we’re in early access.
        </span>
    </div>

    <!-- Result text only (NEVER original input) -->
    <h2 class="pf-heading" style="margin-top:1.25rem;margin-bottom:0.5rem;">
        Rephrased message
    </h2>

    <?php if (!empty($clar['result_text'])): ?>
        <section class="pf-card" style="margin-bottom: 2rem;">
            <h2 class="pf-card-title">TL;DR Summary</h2>
            <div class="pf-box">
                <?= nl2br(htmlspecialchars($clar['result_text'], ENT_QUOTES, 'UTF-8')) ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="pf-card">
        <h2 class="pf-card-title">Full Report</h2>
        <div class="pf-box pf-box--mono">
            <?= nl2br(htmlspecialchars($clar['result_text'], ENT_QUOTES, 'UTF-8')) ?>
        </div>
        <!-- Actions -->
        <div class="pf-actions pf-actions--split" style="margin-top:1.5rem;">
            <a href="/dashboard" class="pf-button pf-button--ghost">
                Back to dashboard
            </a>

            <a href="/clarifications/new" class="pf-button pf-button--primary">
                Start another clarification
            </a>
        </div>
    </section>

        <?php if ($isCancellable): ?>
            <form method="post"
                action="/clarifications/cancel"
                class="pf-actions pf-actions--inline-danger">
                <?php pf_csrf_field(); ?>
                <input type="hidden"
                    name="clarification_id"
                    value="<?= htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') ?>">

                <button type="submit" class="pf-button pf-button--danger-ghost">
                    Cancel and delete this draft
                </button>
            </form>
        <?php endif; ?>
</section>
