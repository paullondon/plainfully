<?php declare(strict_types=1);

// app/dashboard.php

/**
 * Minimal test dashboard so we can confirm routing + shell.
 */
function handle_dashboard(): void
{
    // This will prove the shell and route are both working.
    $innerHtml = '
        <section class="pf-auth-card">
            <h1 class="pf-auth-title">Dashboard</h1>
            <p class="pf-auth-subtitle">
                If you can see this, the dashboard route and shell are wired correctly.
            </p>
        </section>
    ';

    pf_render_shell('Dashboard', $innerHtml);
}
