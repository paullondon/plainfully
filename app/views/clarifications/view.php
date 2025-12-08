<?php
/** @var array $check */
/** @var array $ai */

$shortVerdict = $ai['short_verdict'] ?? '';
$longReport   = $ai['long_report'] ?? '';
$inputCapsule = $ai['input_capsule'] ?? '';
$isScam       = !empty($ai['is_scam']);
$upsellFlags  = $ai['upsell_flags'] ?? [];
?>
<h1 class="pf-page-title">Clarification result</h1>

<p class="pf-page-subtitle">
    We analysed this message based on what you pasted. We never store the full text – only a safe summary.
</p>

<div class="pf-banner pf-banner-plan">
    <?php if ((int)$check['is_paid'] === 1): ?>
        You’re on a paid plan for this clarification. Thanks for supporting Plainfully.
    <?php else: ?>
        You used a free clarification. For frequent checks and priority AI, consider upgrading your plan later.
    <?php endif; ?>
</div>

<section class="pf-panel pf-panel-primary">
    <h2>Verdict</h2>
    <p>
        <?php if ($isScam): ?>
            <span class="pf-pill pf-pill-danger">Likely scam</span>
        <?php else: ?>
            <span class="pf-pill pf-pill-ok">No obvious scam detected</span>
        <?php endif; ?>
    </p>
    <p><?= nl2br(htmlspecialchars($shortVerdict, ENT_QUOTES, 'UTF-8')) ?></p>
</section>

<section class="pf-panel">
    <h2>Detailed explanation</h2>
    <p><?= nl2br(htmlspecialchars($longReport, ENT_QUOTES, 'UTF-8')) ?></p>
</section>

<section class="pf-panel">
    <h2>What we analysed</h2>
    <p class="pf-caption">
        This is a short capsule summary of what you originally pasted, not the full text.
    </p>
    <p><?= nl2br(htmlspecialchars($inputCapsule, ENT_QUOTES, 'UTF-8')) ?></p>
</section>

<?php if (!empty($upsellFlags)): ?>
    <section class="pf-panel pf-panel-soft">
        <h2>Next steps</h2>
        <ul>
            <?php foreach ($upsellFlags as $flag): ?>
                <li><?= htmlspecialchars($flag, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<p style="margin-top:1.5rem;">
    <a href="/clarifications">Back to your clarifications</a> ·
    <a href="/dashboard">Return to dashboard</a>
</p>
