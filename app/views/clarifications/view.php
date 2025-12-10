<?php
/** @var array $vm */
$check        = $vm['check'];
$plan         = $vm['plan'];
$keyPoints    = $vm['key_points'];
$risks        = $vm['risks'];
$nextSteps    = $vm['next_steps'];
$shortVerdict = $vm['short_verdict'];
?>
<h1 class="pf-page-title">Clarification result</h1>

<p class="pf-page-subtitle">
    <?= htmlspecialchars($shortVerdict, ENT_QUOTES, 'UTF-8') ?>
</p>

<div class="pf-plan-banner">
    <strong><?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?></strong>
    <?php if ($plan['used'] !== null && $plan['limit'] !== null): ?>
        – <?= (int)$plan['used'] ?>/<?= (int)$plan['limit'] ?> clarifications used
    <?php endif; ?>
</div>

<div class="pf-result-grid">
    <section class="pf-result-card">
        <h2>Key things to know</h2>
        <ul>
            <?php foreach ($keyPoints as $kp): ?>
                <li><?= htmlspecialchars((string)$kp, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="pf-result-card">
        <h2>Risks / cautions</h2>
        <?php if (!empty($risks)): ?>
            <ul>
                <?php foreach ($risks as $r): ?>
                    <li><?= htmlspecialchars((string)$r, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>No major risks were identified, but you should still be cautious with links and any requests for money or personal details.</p>
        <?php endif; ?>
    </section>

    <section class="pf-result-card">
        <h2>What people typically do with this information</h2>
        <?php if (!empty($nextSteps)): ?>
            <ul>
                <?php foreach ($nextSteps as $n): ?>
                    <li><?= htmlspecialchars((string)$n, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p>Most people use this explanation to decide whether to ignore the message, contact the organisation via a trusted route, or seek further advice.</p>
        <?php endif; ?>
    </section>
</div>
