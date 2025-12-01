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
    <?php
    // Defensive defaults in case view is hit oddly
    $tldrText       = $tldrText       ?? ($clar['result_text'] ?? '');
    $fullReportText = $fullReportText ?? ($clar['result_text'] ?? '');
    $riskLevel      = $riskLevel      ?? 'low';

    // Map risk level → label, icon, CSS modifier
    $riskLabel = 'Low risk';
    $riskIcon  = '✓';
    $riskClass = 'pf-risk-badge--low';

    if ($riskLevel === 'medium') {
        $riskLabel = 'Medium risk';
        $riskIcon  = '!';
        $riskClass = 'pf-risk-badge--medium';
    } elseif ($riskLevel === 'high') {
        $riskLabel = 'High risk';
        $riskIcon  = '!!';
        $riskClass = 'pf-risk-badge--high';
    }
    ?>

    <section class="pf-card" style="margin-bottom: 1.75rem;">
        <h1 class="pf-page-title">Your clarification</h1>
        <p class="pf-page-subtitle">
            Here’s a quick summary first, followed by the full Plainfully report.
        </p>

        <h2 class="pf-card-title" style="margin-top: 1.5rem;">Quick glance</h2>
        <div class="pf-box">
            <div class="pf-quickglance">
                <!-- Left: risk box -->
                <div class="pf-quickglance-card pf-quickglance-card--risk">
                    <div class="pf-risk-badge <?= $riskClass ?>">
                        <span class="pf-risk-badge__icon">
                            <?= htmlspecialchars($riskIcon, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <span class="pf-risk-badge__label">
                            <?= htmlspecialchars($riskLabel, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                    <p class="pf-quickglance-caption">
                        Plainfully’s best guess about how urgent or risky this feels from the wording alone.
                    </p>
                </div>

                <!-- Right: summary box -->
                <div class="pf-quickglance-card pf-quickglance-card--summary">
                    <p class="pf-quickglance-label">Plain explanation (one-line)</p>
                    <div class="pf-quickglance-text">
                        <?= nl2br(htmlspecialchars($tldrText, ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                </div>
            </div>
        </div>
    </section>


    <section class="pf-card">
        <h2 class="pf-card-title">Full report</h2>
        <p class="pf-card-text">
            This section breaks the message down into plain explanation, key things to know,
            risks/cautions, what people typically do, and a short summary.
        </p>

        <div class="pf-box">
            <?= nl2br(htmlspecialchars($fullReportText, ENT_QUOTES, 'UTF-8')) ?>
        </div>

        <div class="pf-upsell" style="margin-top: 1.25rem;">
            On your current plan, Plainfully keeps clarifications for up to
            <strong>28 days</strong> and does not store your original text.
            In future paid plans, you’ll be able to keep reports for longer
            and export them securely.
        </div>

        <div class="pf-actions pf-actions--split" style="margin-top: 1.5rem;">
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
