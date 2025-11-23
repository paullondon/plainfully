<?php declare(strict_types=1);

/**
 * Web Routes for Plainfully
 *
 * Each route maps a path + HTTP method
 * to a controller function.
 */

function pf_register_routes(): array
{
    return [

        // Home (logged-in)
        [
            'method' => 'GET',
            'path'   => '/',
            'action' => function() {
                require_login();
                pf_redirect('/dashboard');;
            },
        ],

        // Login page
        [
            'method' => 'GET',
            'path'   => '/login',
            'action' => function() {
                require_guest();
                handle_login_form($GLOBALS['config']);
            },
        ],

        // Magic link request
        [
            'method' => 'POST',
            'path'   => '/magic/request',
            'action' => function() {
                require_guest();
                handle_magic_request($GLOBALS['config']);
            },
        ],

        // Magic link verify
        [
            'method' => 'GET',
            'path'   => '/magic/verify',
            'action' => function() {
                require_guest();
                handle_magic_verify();
            },
        ],

        // Logout
        [
            'method' => 'POST',
            'path'   => '/logout',
            'action' => function() {
                require_login();
                handle_logout();
            },
        ],

        // Health check
        [
            'method' => 'GET',
            'path'   => '/health',
            'action' => function() {
                require_guest();
                handle_health($GLOBALS['config']);
            },
        ],

        // dashboard
        [
            'method' => 'GET',
            'path'   => '/dashboard',
            'action' => function () {
                require_login();
                handle_dashboard();
            }
        ],


// new routes abover here
    ];
}
