<?php declare(strict_types=1); ?>

<div class="pf-404-wrapper">
    <div class="pf-404-card">
        <div class="pf-404-emoji">🤔</div>

        <h1 class="pf-404-title">We couldn’t find that page</h1>

        <p class="pf-404-subtitle">
            Looks like the link doesn’t match anything in Plainfully right now.
        </p>

        <ul class="pf-404-list">
            <li>Double-check the address for typos.</li>
            <li>Use your browser’s back button to return.</li>
            <li>Or head to your dashboard to view your clarifications.</li>
        </ul>

        <div class="pf-404-actions">
            <a href="/dashboard" class="pf-btn pf-btn-primary">Go to dashboard</a>
            <a href="/login" class="pf-btn pf-btn-secondary">Log in</a>
        </div>
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
    max-width: 500px;
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
    max-width: 420px;
}

.pf-404-actions {
    display: flex;
    justify-content: center;
    gap: 12px;
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
