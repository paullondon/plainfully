<?php
/**
 * @var string $userNameSafe
 * @var string $planLabelSafe
 * @var string $planKeySafe
 * @var string $planTaglineSafe
 * @var array  $recentConsultations
 */
?>
<section class="pf-dashboard">

    <!-- Top row: welcome + plan badge / upsell -->
    <header class="pf-dashboard-header">
        <div class="pf-dashboard-welcome">
            <h1 class="pf-dashboard-title">Welcome back, <?= $userNameSafe; ?></h1>
            <p class="pf-dashboard-subtitle">
                Turn messy drafts and confusing letters into clear, confident wording.
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

    <!-- Main CTA + recent section -->
    <div class="pf-dashboard-main-grid">
        <!-- Left: Start new consultation -->
        <section class="pf-dashboard-card pf-dashboard-card--primary">
            <h2 class="pf-card-title">Start a new consultation</h2>
            <p class="pf-card-text">
                Describe what you’re dealing with, attach your draft, and we’ll help you
                turn it into something clear, firm, and fair.
            </p>
            <a href="/clarifications/new" class="pf-btn pf-btn--primary pf-dashboard-cta">
                Start new consultation
            </a>
        </section>

        <!-- Right: Quick recent summary -->
        <section class="pf-dashboard-card pf-dashboard-card--secondary">
            <h2 class="pf-card-title">Recent consultations</h2>

            <?php if (empty($recentConsultations)): ?>
                <p class="pf-card-text pf-card-text--muted">
                    You haven’t created any consultations yet.
                    Start your first one to see it appear here.
                </p>
            <?php else: ?>
                <ul class="pf-list pf-list--recent">
                    <?php foreach ($recentConsultations as $item): ?>
                        <?php
                        $title = htmlspecialchars((string)($item['title'] ?? 'Untitled consultation'), ENT_QUOTES, 'UTF-8');
                        $createdAt = htmlspecialchars((string)($item['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $id = (int)($item['id'] ?? 0);
                        ?>
                        <li class="pf-list-item">
                            <a href="/clarifications/view?id=<?= $id; ?>" class="pf-list-link">
                                <span class="pf-list-title"><?= $title; ?></span>
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

    <!-- Optional: a tertiary area for “How Plainfully works” or tips later -->
</section>
