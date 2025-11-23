<?php declare(strict_types=1);

function handle_dashboard(): void
{
    // Basic placeholder HTML
    $html = '
        <h1 class="pf-page-title">Welcome to your dashboard</h1>
        <p class="pf-page-subtitle">
            You are now signed in and ready to use Plainfully.
        </p>

        <div class="pf-dashboard-cards">
            <div class="pf-card">
                <h2>New clarification</h2>
                <p>Create a new plain-English breakdown.</p>
                <a href="#" class="pf-button">Create</a>
            </div>

            <div class="pf-card">
                <h2>Your history</h2>
                <p>View all your previous clarifications.</p>
                <a href="#" class="pf-button">Open</a>
            </div>
        </div>
    ';

    pf_render_shell('Dashboard', $html);
}
