<?php
/** @var array  $clar */
/** @var bool   $isCompleted */
/** @var bool   $isCancellable */
/** @var string $pageTitle */

$id          = (int)$clar['id'];
$tone        = $clar['tone'] ?? 'notapplicable';
$status      = $clar['status'] ?? 'completed';
$resultText  = $clar['result_text'] ?? '';
$createdAt   = $clar['created_at'] ?? null;
$completedAt = $clar['completed_at'] ?? null;

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

<?php
    /**  Defensive defaults in case view is hit oddly */
    $tldrText       = $tldrText       ?? ($clar['result_text'] ?? '');
    $fullReportText = $fullReportText ?? ($clar['result_text'] ?? '');
    $riskLevel      = $riskLevel      ?? 'low';

    $keyPoints   = $keyPoints   ?? [];
    $risksText   = $risksText   ?? 'Plainfully will highlight any risks or cautions here.';
    $actionsList = $actionsList ?? [];

    /**  Map risk level → label, icon, CSS modifier + explanation */
    $riskLabel       = 'Low risk';
    $riskIcon        = '✓';
    $riskClass       = 'pf-risk-badge--low';
    $riskSectionClass = 'pf-result-section--risk-low';
    $riskExplanation = 'Based on the wording, this doesn’t sound urgent or threatening. Still, double-check anything important like dates, amounts or deadlines.';

    if ($riskLevel === 'medium') {
        $riskLabel        = 'Medium risk';
        $riskIcon         = '!';
        $riskClass        = 'pf-risk-badge--medium';
        $riskSectionClass = 'pf-result-section--risk-medium';
        $riskExplanation  = 'Parts of this message sound time-sensitive or important. It’s worth reading the original carefully and checking dates, amounts or commitments.';
    } elseif ($riskLevel === 'high') {
        $riskLabel        = 'High risk';
        $riskIcon         = '!!';
        $riskClass        = 'pf-risk-badge--high';
        $riskSectionClass = 'pf-result-section--risk-high';
        $riskExplanation  = 'The wording suggests something urgent, serious, or with possible consequences if ignored. Read the original document closely and consider getting advice if you’re unsure.';
    }
    ?>

    /** Plainfully Simple Clarification (quick glance) */
    <section class="pf-result-section pf-result-section--primary <?= $riskSectionClass ?>">
        <h2 class="pf-result-heading">Plainfully Simple Clarification</h2>

        <div class="pf-quickglance">
            /** Left: risk */
            <div class="pf-quickglance-card pf-quickglance-card--risk">
                <div class="pf-risk-badge <?= $riskClass ?>">
                    <span class="pf-risk-badge__icon">
                        <?= htmlspecialchars($riskIcon, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="pf-risk-badge__label">
                        <?= htmlspecialchars($riskLabel, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            </div>

            /** Right: TL;DR text */
            <div class="pf-quickglance-card pf-quickglance-card--summary">
                <p class="pf-result-body">
                    <?= nl2br(htmlspecialchars($tldrText, ENT_QUOTES, 'UTF-8')) ?>
                </p>
            </div>
        </div>

        <p class="pf-risk-explainer">
            <?= htmlspecialchars($riskExplanation, ENT_QUOTES, 'UTF-8') ?>
        </p>
    </section>

    /** Key things to know */
    <section class="pf-result-section pf-result-section--secondary">
        <h2 class="pf-result-heading">Key things to know</h2>
        <?php if (!empty($keyPoints)): ?>
            <ul class="pf-fullreport-list">
                <?php foreach ($keyPoints as $point): ?>
                    <li><?= htmlspecialchars($point, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="pf-result-body">
                Plainfully will highlight the main points from your message here.
            </p>
        <?php endif; ?>
    </section>

    /** Risks / cautions */
    <section class="pf-result-section pf-result-section--secondary">
        <h2 class="pf-result-heading">Risks / cautions</h2>
        <p class="pf-result-body">
            <?= nl2br(htmlspecialchars($risksText, ENT_QUOTES, 'UTF-8')) ?>
        </p>
    </section>

    /** What people typically do */
    <section class="pf-result-section pf-result-section--secondary">
        <h2 class="pf-result-heading">What people typically do in this situation</h2>
        <?php if (!empty($actionsList)): ?>
            <ul class="pf-fullreport-list">
                <?php foreach ($actionsList as $action): ?>
                    <li><?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="pf-result-body">
                Plainfully will suggest a few common next steps people take in similar situations.
            </p>
        <?php endif; ?>

        /** Report footer: you're all caught up + actions */
        <div class="pf-report-footer">
            <div class="pf-report-footer-text">
                You’re all caught up on this message.
            </div>
            <div class="pf-actions pf-actions--split" style="margin-top:0;">
                /** Non-paying: export button is disabled/greyed out for now */
                <button type="button"
                        class="pf-button pf-button--ghost pf-button--ghost-disabled"
                        disabled>
                    Email this report (Pro)
                </button>

                <a href="/dashboard" class="pf-button pf-button--ghost">
                    Back to dashboard
                </a>
                <a href="/clarifications/new" class="pf-button pf-button--primary">
                    Start another clarification
                </a>
            </div>
        </div>
    </section>

    /** Primary actions */
    <div class="pf-section" style="border-top:none;padding-top:1.25rem;">
        <div class="pf-allcaughtup">
            <p>You’re all caught up.</p>
        </div>

        <div class="pf-actions pf-actions--split">
            <a href="/dashboard" class="pf-button pf-button--ghost">
                Back to dashboard
            </a>

            <?php if ($isPaidUser): ?>
                /** Paid users: real email action */
                <form method="post"
                    action="/clarifications/email"
                    style="margin:0; display:inline;">
                    <?php pf_csrf_field(); ?>
                    <input type="hidden"
                        name="clarification_id"
                        value="<?= htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="pf-button pf-button--secondary">
                        Email me this report
                    </button>
                </form>
            <?php else: ?>
                /** Free users: greyed-out Pro teaser */
                <button type="button"
                        class="pf-button pf-button--secondary pf-button--disabled"
                        disabled>
                    Email me this report (Pro)
                </button>
            <?php endif; ?>

            <a href="/clarifications/new" class="pf-button pf-button--primary">
                Start another clarification
            </a>
        </div>

        <?php if ($isCancellable): ?>
            <form method="post"
                action="/clarifications/cancel"
                class="pf-actions pf-actions--inline-danger"
                style="margin-top:0.75rem;">
                <?php pf_csrf_field(); ?>
                <input type="hidden"
                    name="clarification_id"
                    value="<?= htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') ?>">

                <button type="submit" class="pf-button pf-button--danger-ghost">
                    Cancel and delete this draft
                </button>
            </form>
        <?php endif; ?>
    </div>
</section>
