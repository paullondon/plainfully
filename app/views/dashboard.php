<?php
/**
 * Dashboard inner view.
 *
 * Expects:
 *  - string $userNameSafe
 *  - string $planLabelSafe
 *  - string $planKeySafe  (basic|pro|unlimited)
 *  - string $planTaglineSafe
 *  - array  $recentConsultations
 */
?>
<section class="pf-dashboard">

    <!-- Top row: welcome + plan / upsell -->
    <header class="pf-dashboard-header">
        <div class="pf-dashboard-welcome">
            <h1 class="pf-dashboard-title">Welcome back, <?= $userNameSafe; ?></h1>
            <p class="pf-dashboard-subtitle">
                Turn stressful letters and forms into clear, confident responses you’re happy to send.
            </p>
        </div>

        <aside class="pf-dashboard-plan">
            <span class="pf-plan-label">Your plan</span>
            <div class="pf-plan-chip pf-plan-chip--<?= $planKeySafe; ?>">
                <?= $planLabelSafe; ?>
            </div>
            <p class="pf-plan-tagline">
                <?= $planTaglineSafe; ?>
            </p>

            <?php if ($planKeySafe !== 'unlimited'): ?>
                <form action="/billing/upgrade" method="get" class="pf-plan-upgrade">
                    <button type="submit" class="pf-btn pf-btn--primary">
                        Upgrade plan
                    </button>
                </form>
            <?php else: ?>
                <p class="pf-plan-note">
                    You’re on our top plan. Thank you for supporting Plainfully.
                </p>
            <?php endif; ?>
        </aside>
    </header>

    <!-- Main grid: primary CTA + recent consultations -->
    <div class="pf-dashboard-main-grid">
        <!-- Left: Start new consultation -->
        <section class="pf-dashboard-card pf-dashboard-card--primary">
            <h2 class="pf-card-title">Start a new consultation</h2>
            <p class="pf-card-text">
                Tell us the situation and share your draft or notes. Plainfully will help rewrite it
                into something calm, clear, and direct – ready to send.
            </p>
            <a href="/clarifications/new" class="pf-btn pf-btn--primary pf-dashboard-cta">
                Start a new consultation
            </a>

            <!-- Upsell banner inside the primary card -->
            <?php if ($planKeySafe === 'basic'): ?>
                <div class="pf-upsell-banner">
                    <p class="pf-upsell-text">
                        On Basic you can run a limited number of consultations each month.
                        Upgrade to <strong>Pro</strong> for more usage and faster queue priority.
                    </p>
                    <a href="/billing/upgrade" class="pf-link pf-link--inline">See Pro benefits →</a>
                </div>
            <?php elseif ($planKeySafe === 'pro'): ?>
                <div class="pf-upsell-banner pf-upsell-banner--soft">
                    <p class="pf-upsell-text">
                        Using Plainfully regularly? <strong>Unlimited</strong> removes usage caps,
                        ideal if you’re supporting others or handling lots of letters.
                    </p>
                    <a href="/billing/upgrade" class="pf-link pf-link--inline">View Unlimited →</a>
                </div>
            <?php endif; ?>
        </section>

        <!-- Right: Recent consultations (Plainfully results only) -->
        <section class="pf-dashboard-card pf-dashboard-card--secondary">
            <h2 class="pf-card-title">Recent consultations</h2>

            <?php if (empty($recentConsultations)): ?>
                <p class="pf-card-text pf-card-text--muted">
                    You haven’t completed any consultations yet.
                    When you use Plainfully, we’ll list the outcomes here –
                    just the results we produced, not what you originally wrote.
                </p>
            <?php else: ?>
                <ul class="pf-list pf-list--recent">
                    <?php foreach ($recentConsultations as $item): ?>
                        <?php
                        $id          = (int)($item['id'] ?? 0);
                        $resultTitle = htmlspecialchars((string)($item['result_title'] ?? 'Plainfully result'), ENT_QUOTES, 'UTF-8');
                        $createdAt   = htmlspecialchars((string)($item['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $status      = htmlspecialchars((string)($item['status'] ?? ''), ENT_QUOTES, 'UTF-8');
                        ?>
                        <li class="pf-list-item">
                            <a href="/clarifications/view?id=<?= $id; ?>" class="pf-list-link">
                                <div class="pf-list-main">
                                    <span class="pf-list-title"><?= $resultTitle; ?></span>
                                    <?php if ($status !== ''): ?>
                                        <span class="pf-list-status"><?= $status; ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($createdAt !== ''): ?>
                                    <span class="pf-list-meta"><?= $createdAt; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a href="/clarifications" class="pf-link pf-link--subtle">
                    View all consultations
                </a>
            <?php endif; ?>
        </section>
    </div>
</section>
