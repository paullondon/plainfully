<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/views/errors/404.php
 * Purpose:
 *   Single adaptive error view (404 + token/validation failures).
 *
 * How it works:
 *   - If $vm is provided (array), it will override title/subtitle/etc.
 *   - If not provided, it behaves as the standard 404 page.
 *
 * Change history:
 *   - 2025-12-29: Adapted to accept $vm payload for all error cases.
 * ============================================================
 */

$vm = (isset($vm) && is_array($vm)) ? $vm : [];

$emoji    = (string)($vm['emoji'] ?? '🤔');
$title    = (string)($vm['title'] ?? 'We couldn’t find that page');
$subtitle = (string)($vm['subtitle'] ?? 'Looks like the link doesn’t match anything in Plainfully right now.');

$list = $vm['list'] ?? [
    'Double-check the address for typos.',
    'Use your browser’s back button to return.',
    'Or head to your dashboard to view your clarifications.',
];
if (!is_array($list)) { $list = []; }

$actions = $vm['actions'] ?? [
    ['href' => '/dashboard', 'label' => 'Go to dashboard', 'class' => 'pf-btn pf-btn-primary'],
    ['href' => '/login',     'label' => 'Log in',          'class' => 'pf-btn pf-btn-secondary'],
];
if (!is_array($actions)) { $actions = []; }

// Safety escape
$emojiSafe    = htmlspecialchars($emoji, ENT_QUOTES, 'UTF-8');
$titleSafe    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
$subtitleSafe = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
?>

<div class="pf-404-wrapper">
    <div class="pf-404-card">
        <div class="pf-404-emoji"><?= $emojiSafe ?></div>

        <h1 class="pf-404-title"><?= $titleSafe ?></h1>

        <p class="pf-404-subtitle"><?= $subtitleSafe ?></p>

        <?php if (!empty($list)) : ?>
            <ul class="pf-404-list">
                <?php foreach ($list as $item) :
                    $itemSafe = htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8'); ?>
                    <li><?= $itemSafe ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($actions)) : ?>
            <div class="pf-404-actions">
                <?php foreach ($actions as $a) :
                    if (!is_array($a)) { continue; }
                    $href  = htmlspecialchars((string)($a['href'] ?? '/login'), ENT_QUOTES, 'UTF-8');
                    $label = htmlspecialchars((string)($a['label'] ?? 'Continue'), ENT_QUOTES, 'UTF-8');
                    $class = htmlspecialchars((string)($a['class'] ?? 'pf-btn pf-btn-secondary'), ENT_QUOTES, 'UTF-8');
                ?>
                    <a href="<?= $href ?>" class="<?= $class ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.pf-404-wrapper {
    display: flex;
    justify-content: center;
    padding: 40px 20px;
}

.pf-404-card {
    background: var(--pf-surface);
    border: 1px solid var(--pf-border-subtle);
    border-radius: 16px;
    padding: 40px;
    max-width: 540px;
    width: 100%;
    text-align: center;
}

.pf-404-emoji {
    font-size: 48px;
    margin-bottom: 16px;
}

.pf-404-title {
    color: var(--pf-text-main);
    font-size: 28px;
    margin-bottom: 8px;
}

.pf-404-subtitle {
    color: var(--pf-text-muted);
    margin-bottom: 20px;
}

.pf-404-list {
    text-align: left;
    color: var(--pf-text-soft);
    margin: 0 auto 24px auto;
    max-width: 440px;
}

.pf-404-actions {
    display: flex;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
}

.pf-btn {
    padding: 10px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 500;
}
.pf-btn-primary {
    background: var(--pf-accent);
    color: #000;
}
.pf-btn-secondary {
    background: var(--pf-surface-soft);
    color: var(--pf-text-main);
}
</style>
