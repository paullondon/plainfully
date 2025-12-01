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

/* ---------- View-safe defaults for A3 ---------- */

$tldrText       = $tldrText       ?? ($clar['result_text'] ?? '');
$fullReportText = $fullReportText ?? ($clar['result_text'] ?? '');

$riskLevel  = $riskLevel ?? 'low';
$riskLabel  = 'Low risk';
$riskIcon   = '✓';
$riskClass  = 'pf-risk-badge--low';

if ($riskLevel === 'medium') {
    $riskLabel = 'Medium risk';
    $riskIcon  = '!';
    $riskClass = 'pf-risk-badge--medium';
} elseif ($riskLevel === 'high') {
    $riskLabel = 'High risk';
    $riskIcon  = '!!';
    $riskClass = 'pf-risk-badge--high';
}

$keyPoints   = $keyPoints   ?? [];
$risksText   = $risksText   ?? 'Plainfully will highlight any risks or cautions here.';
$actionsList = $actionsList ?? [];
?>

<section class="pf-card pf-card--narrow">
    <!-- Header + meta -->
    <header style="margin-bottom:1.1rem;">
        <h1 class="pf-page-title">Your clarification</h1>
        <p class="pf-page-subtitle">
            Plainfully has rephrased your message into something easier to scan and act on.
            We never show the original text here for your privacy.
        </p>
    </header>

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
        In the future you’ll be able to upgrade for richer history and extra tools.
        <br>
        <span style="font-size:0.8rem;color:var(--pf-text-muted);">
            For now, enjoy unlimited clarifications while we’re in early access.
        </span>
    </div>

    <!-- SECTION 1: Plainfully Simple Clarification -->
    <div class="pf-section">
        <h2 class="pf-card-title">Plainfully Simple Clarification</h2>
        <div class="pf-box pf-box--quickglance">
            <div class="pf-quickglance">
                <!-- Left: risk badge -->
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

                <!-- Right: summary text -->
                <div class="pf-quickglance-card pf-quickglance-card--summary">
                    <div class="pf-quickglance-text">
                        <?= nl2br(htmlspecialchars($tldrText, ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 2: Key things to know -->
    <div class="pf-section">
        <h2 class="pf-card-title">Key things to know</h2>
        <div class="pf-box">
            <?php if (!empty($keyPoints)): ?>
                <ul class="pf-fullreport-list">
                    <?php foreach ($keyPoints as $point): ?>
                        <li><?= htmlspecialchars($point, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="pf-fullreport-body">
                    Plainfully will highlight the main points from your message here.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- SECTION 3: Risks / cautions -->
    <div class="pf-section">
        <h2 class="pf-card-title">Risks / cautions</h2>
        <div class="pf-box">
            <p class="pf-fullreport-body">
                <?= nl2br(htmlspecialchars($risksText, ENT_QUOTES, 'UTF-8')) ?>
            </p>
        </div>
    </div>

    <!-- SECTION 4: What people typically do -->
    <div class="pf-section">
        <h2 class="pf-card-title">What people typically do in this situation</h2>
        <div class="pf-box">
            <?php if (!empty($actionsList)): ?>
                <ul class="pf-fullreport-list">
                    <?php foreach ($actionsList as $action): ?>
                        <li><?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="pf-fullreport-body">
                    Plainfully will suggest a few common next steps people take in similar situations.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Primary actions -->
    <div class="pf-section" style="border-top:none;padding-top:1.25rem;">
        <div class="pf-actions pf-actions--split">
            <a href="/dashboard" class="pf-button pf-button--ghost">
                Back to dashboard
            </a>
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
