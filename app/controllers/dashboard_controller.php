<?php declare(strict_types=1);

// app/dashboard.php

/**
 * Plainfully – Dashboard handler
 *
 * This is the logged-in landing page after /login.
 * It shows:
 *  - Primary CTA to start a new consultation
 *  - Current subscription plan + upgrade CTA
 *  - Recent consultations list (stubbed for now)
 */
function handle_dashboard(): void
{
    // Basic, safe defaults – you can replace these with real DB lookups later.
    $userName  = $_SESSION['user_name']         ?? 'there';
    $planKey   = $_SESSION['subscription_plan'] ?? 'basic';

    // Normalise plan key to avoid weird values breaking display
    $planKey = strtolower((string)$planKey);

    $planLabels = [
        'basic'     => 'Basic',
        'pro'       => 'Pro',
        'unlimited' => 'Unlimited',
    ];
    $planTaglines = [
        'basic'     => 'Great for occasional letters and one-off clarifications.',
        'pro'       => 'For regular users who want faster responses and more volume.',
        'unlimited' => 'Best for heavy users – maximum flexibility and priority.',
    ];

    $planLabel   = $planLabels[$planKey]   ?? $planLabels['basic'];
    $planTagline = $planTaglines[$planKey] ?? $planTaglines['basic'];

    // TODO: replace this stub with a real query once your clarifications table exists.
    $recentConsultations = []; // e.g. array of ['id' => 1, 'title' => 'Re: PIP appeal', 'created_at' => '2025-02-20']

    // Render the dashboard view into a buffer, then pass it to the shell.
    ob_start();

    // Expose variables to the view:
    $userNameSafe  = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $planLabelSafe = htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8');
    $planKeySafe   = htmlspecialchars($planKey, ENT_QUOTES, 'UTF-8');
    $planTaglineSafe = htmlspecialchars($planTagline, ENT_QUOTES, 'UTF-8');

    // $recentConsultations intentionally left as raw array – we’ll escape inside the view.
    require __DIR__ . '/views/dashboard.php';

    $innerHtml = (string)ob_get_clean();

    // pf_render_shell is your main layout wrapper defined in render.php
    pf_render_shell('Dashboard', $innerHtml);
}