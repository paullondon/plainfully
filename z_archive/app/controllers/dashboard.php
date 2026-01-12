<?php declare(strict_types=1);
/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/controllers/dashboard.php
 * Purpose:
 *   Logged-in landing page (dashboard).
 *
 * What this version adds:
 *   - Flow B support: if a result-link flow sets $_SESSION['pf_return_to'],
 *     the dashboard immediately redirects there (one-time) and exits.
 *
 * Change history:
 *   - 2025-12-28: Add pf_return_to redirect guard + standard file info header
 * ============================================================
 */

function handle_dashboard(): void
{
    // Flow B: if a result-link flow set a one-time return target, honour it.
    if (!empty($_SESSION['pf_return_to'])) {
        $to = (string)$_SESSION['pf_return_to'];
        unset($_SESSION['pf_return_to']);

        // Basic hardening: only allow internal paths to prevent open-redirects.
        if ($to !== '' && str_starts_with($to, '/')) {
            pf_redirect($to);
            exit;
        }
    }

    // We assume require_login() has already run in routes/web.php
    $userName  = $_SESSION['user_name']         ?? 'there';
    $planKey   = $_SESSION['subscription_plan'] ?? 'basic';

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
     * IMPORTANT PRIVACY RULE:
     * Only store/display Plainfully's *output*, not raw user input.
     *
     * Shape suggestion:
     * [
     *   'id'           => int,
     *   'result_title' => string, // e.g. "Letter drafted: ESA reconsideration"
     *   'created_at'   => string, // preformatted date/time
     *   'status'       => string, // e.g. "sent", "draft"
     * ]
     */
    $recentConsultations = []; // TODO: replace with real DB lookup later

    // Prepare safe variables for the view
    $userNameSafe     = htmlspecialchars((string)$userName, ENT_QUOTES, 'UTF-8');
    $planLabelSafe    = htmlspecialchars((string)$planLabel, ENT_QUOTES, 'UTF-8');
    $planKeySafe      = htmlspecialchars((string)$planKey, ENT_QUOTES, 'UTF-8');
    $planTaglineSafe  = htmlspecialchars((string)$planTagline, ENT_QUOTES, 'UTF-8');

    // Render the view into a buffer
    ob_start();
    require __DIR__ . '/../views/dashboard.php';
    $innerHtml = (string)ob_get_clean();

    // Wrap with the global shell (app/views/render.php)
    pf_render_shell('Dashboard', $innerHtml);
}
