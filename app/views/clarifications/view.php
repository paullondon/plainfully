<?php
/** @var array  $clar */
/** @var bool   $isCancellable */
/** @var string $pageTitle */
/** @var array|null $usage */
/** @var string|null $riskLevel */
/** @var array|null $keyPoints */
/** @var array|null $riskPoints */
/** @var array|null $actionsList */

$id          = (int)($clar['id'] ?? 0);
$status      = $clar['status'] ?? 'completed';
$createdAt   = $clar['created_at'] ?? null;
$completedAt = $clar['completed_at'] ?? null;

/**
 * Very small date formatter – keeps the UI consistent and avoids fatal errors.
 */
function pf_fmt_dt(?string $dt): string
{
    if (!$dt) {
        return '';
    }

    try {
        $dtObj = new DateTimeImmutable($dt);
        return $dtObj->format('j M Y, H:i');
    } catch (Throwable $e) {
        // Don’t explode the page if something weird is stored
        error_log('[Plainfully] Failed to format date in view: ' . $e->getMessage());
        return $dt;
    }
}

/**
 * Derive usage / plan info with safe defaults.
 */
$usage            = $usage ?? [];
$planName         = $usage['plan_name']         ?? 'Basic';
$limit            = $usage['limit']             ?? 3;          // null = unlimited
$used             = $usage['used']              ?? 0;
$nextAvailableRaw = $usage['next_available_at'] ?? null;

$hasLimit    = $limit !== null;
$remaining   = $hasLimit ? max(0, (int)$limit - (int)$used) : null;
$atLimit     = $hasLimit && $remaining === 0;
$usageLabel  = $hasLimit
    ? sprintf('%d / %d clarifications used', (int)$used, (int)$limit)
    : 'Unlimited clarifications';

/**
 * If we know when the next slot frees up, turn it into a small
 * “next in X days Y hours” string.
 */
$nextSlotText = '';
if ($atLimit && $nextAvailableRaw) {
    try {
        $next = new DateTimeImmutable($nextAvailableRaw);
        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($next > $now) {
            $diff = $now->diff($next);
            $parts = [];
            if ($diff->d > 0) {
                $parts[] = $diff->d . ' day' . ($diff->d === 1 ? '' : 's');
            }
            if ($diff->h > 0) {
                $parts[] = $diff->h . ' hour' . ($diff->h === 1 ? '' : 's');
            }
            if ($diff->i > 0 && empty($parts)) {
                // Only show minutes if we don’t already have days/hours
                $parts[] = $diff->i . ' minute' . ($diff->i === 1 ? '' : 's');
            }

            if (!empty($parts)) {
                $nextSlotText = 'Next clarification becomes available in ' . implode(' ', $parts) . '.';
            }
        }
    } catch (Throwable $e) {
        error_log('[Plainfully] Failed to compute next-slot text: ' . $e->getMessage());
    }
}

/**
 * Risk + explanation mapping
 */
$riskLevel = $riskLevel ?? 'low';

$riskLabel      = 'Low risk';
$riskBadgeClass = 'pf-risk-badge--low';
$riskExplain    = 'Based on the wording, this doesn’t sound urgent or threatening. Still, double-check anything about money, dates or deadlines.';

if ($riskLevel === 'medium') {
    $riskLabel      = 'Medium risk';
    $riskBadgeClass = 'pf-risk-badge--medium';
    $riskExplain    = 'Parts of the wording might have consequences if misunderstood. Read carefully and consider asking the sender to clarify anything that feels unclear.';
} elseif ($riskLevel === 'high') {
    $riskLabel      = 'High risk';
    $riskBadgeClass = 'pf-risk-badge--high';
    $riskExplain    = 'The wording suggests this could affect money, contracts, legal matters, or important deadlines. Treat this as high priority and seek help if you are unsure.';
}

/**
 * Lists for the three sections – safe defaults so the page is never empty.
 */
$keyPoints   = $keyPoints   ?? [];
$riskPoints  = $riskPoints  ?? [];
$actionsList = $actionsList ?? [];

if (empty($keyPoints)) {
    $keyPoints = [
        'Plainfully will list the main points from your message here.',
        'Each bullet is written in simple language to make scanning easier.',
    ];
}

if (empty($riskPoints)) {
    $riskPoints = [
        'Plainfully will highlight any sentences that sound demanding, urgent, or financially risky.',
        'You can use these points as a checklist before you reply or decide what to do.',
    ];
}

if (empty($actionsList)) {
    $actionsList = [
        'Re-read the key points slowly to make sure they match your understanding.',
        'Check any dates, amounts, or deadlines mentioned in the original message.',
        'If you are unsure, contact the sender or a trusted person and show them this summary.',
    ];
}
?>
<section class="pf-card pf-card--narrow">

    <!-- Plan + usage band -->
    <div class="pf-plan-band">
        <div class="pf-plan-band__main">
            <span class="pf-plan-chip pf-plan-chip--<?= htmlspecialchars(strtolower($planName), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars('Plainfully ' . $planName, ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span class="pf-plan-band__usage">
                <?= htmlspecialchars($usageLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <div class="pf-plan-band__meta">
            <?php if ($hasLimit): ?>
                <?php if ($atLimit && $nextSlotText !== ''): ?>
                    <span class="pf-plan-band__warning">
                        <?= htmlspecialchars($nextSlotText, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php else: ?>
                    <span class="pf-plan-band__hint">
                        <?= htmlspecialchars($remaining . ' clarifications left in this 28-day window.', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
                <a href="/pricing" class="pf-link pf-plan-band__upgrade">Upgrade for more →</a>
            <?php else: ?>
                <span class="pf-plan-band__hint">
                    You’re on an unlimited plan. Use Plainfully as often as you like.
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Simple meta line -->
    <header class="pf-report-header">
        <h1 class="pf-page-title">Your clarification</h1>
        <p class="pf-page-subtitle">
            Plainfully has rephrased your message into something easier to scan and act on.
            We never show the original text here for your privacy.
        </p>

        <div class="pf-report-meta">
            <span>#<?= htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($createdAt): ?>
                <span>• Started: <?= htmlspecialchars(pf_fmt_dt($createdAt), ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <?php if ($completedAt): ?>
                <span>• Completed: <?= htmlspecialchars(pf_fmt_dt($completedAt), ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <span>• Status: <?= htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </header>

    <!-- 1. Key things to know -->
    <section class="pf-report-card pf-report-card--key">
        <h2 class="pf-report-card__title">Key things to know</h2>
        <p class="pf-report-card__subtitle">
            A short list of the main points from the message, written in plain language.
        </p>
        <ul class="pf-report-list">
            <?php foreach ($keyPoints as $point): ?>
                <li><?= htmlspecialchars($point, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <!-- 2. Risks / cautions -->
    <section class="pf-report-card pf-report-card--risks">
        <h2 class="pf-report-card__title">Risks / cautions</h2>
        <p class="pf-report-card__subtitle">
            How “risky” the wording sounds, and what to pay extra attention to.
        </p>

        <div class="pf-report-risk">
            <span class="pf-risk-badge <?= $riskBadgeClass ?>">
                <span class="pf-risk-badge__icon"></span>
                <span class="pf-risk-badge__label"><?= htmlspecialchars($riskLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </span>
            <p class="pf-report-risk__explain">
                <?= htmlspecialchars($riskExplain, ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <ul class="pf-report-list">
            <?php foreach ($riskPoints as $point): ?>
                <li><?= htmlspecialchars($point, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <!-- 3. What people typically do with this information -->
    <section class="pf-report-card pf-report-card--actions">
        <h2 class="pf-report-card__title">What people typically do with this information</h2>
        <p class="pf-report-card__subtitle">
            Typical next steps people take after receiving a message like this.
        </p>
        <ul class="pf-report-list">
            <?php foreach ($actionsList as $action): ?>
                <li><?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <!-- All-caught-up note + actions -->
    <div class="pf-report-footer">
        <p class="pf-report-footer__text">
            You’re all caught up on this message.
        </p>

        <div class="pf-actions pf-actions--split pf-report-footer__actions">
            <a href="/dashboard" class="pf-button pf-button--ghost">
                Back to dashboard
            </a>

            <!-- Greyed-out email export for non-paying users (hook logic later) -->
            <button class="pf-button pf-button--ghost pf-button--disabled" type="button" disabled>
                Email this report (Pro)
            </button>

            <a href="/clarifications/new" class="pf-button pf-button--primary">
                Start another clarification
            </a>
        </div>
    </div>

    <?php if (!empty($isCancellable)): ?>
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
