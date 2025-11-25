<?php declare(strict_types=1);

// app/dashboard.php

/**
 * Plainfully – Dashboard handler
 *
 * Logged-in landing page. Shows:
 *  - Primary CTA to start a new consultation
 *  - Current subscription plan + upgrade CTA
 *  - Recent consultations (Plainfully's results only)
 */
function handle_dashboard(): void
{
    $userName  = $_SESSION['user_name']         ?? 'there';
    $planKey   = $_SESSION['subscription_plan'] ?? 'basic';

    // Normalise plan key to avoid weird values causing CSS weirdness
    $planKey = strtolower((string)$planKey);

    $planLabels = [
        'basic'     => 'Basic',
        'pro'       => 'Pro',
        'unlimited' => 'Unlimited',
    ];
    $planTaglines = [
        'basic'     => 'Great for occasional letters and one-off clarifications.',
        'pro'       => 'For regular use with more consultations and faster responses.',
        'unlimited' => 'Best for heavy use – maximum flexibility and priority handling.',
    ];

    $planLabel   = $planLabels[$planKey]   ?? $planLabels['basic'];
    $planTagline = $planTaglines[$planKey] ?? $planTaglines['basic'];

    /**
     * IMPORTANT PRIVACY NOTE:
     * $recentConsultations should only contain data that Plainfully produced,
     * e.g. a short result title or label – NOT the original user text.
     *
     * Suggested shape for each item:
     *  [
     *      'id'            => int,    // internal ID
     *      'result_title'  => string, // e.g. "Letter drafted: ESA reconsideration"
     *      'created_at'    => string, // pre-formatted date/time for display
     *      'status'        => string, // e.g. "sent", "draft", "downloaded"
     *  ]
     */
    $recentConsultations = []; // TODO: replace with DB fetch when ready

    // Render the dashboard view into a buffer, then pass it to the shell.
    ob_start();

    $userNameSafe     = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $planLabelSafe    = htmlspecialchars($planLabel, ENT_QUOTES, 'UTF-8');
    $planKeySafe      = htmlspecialchars($planKey, ENT_QUOTES, 'UTF-8');
    $planTaglineSafe  = htmlspecialchars($planTagline, ENT_QUOTES, 'UTF-8');

    // $recentConsultations is escaped inside the view.
    require __DIR__ . '/views/dashboard.php';

    $innerHtml = (string)ob_get_clean();

    pf_render_shell('Dashboard', $innerHtml);
}
