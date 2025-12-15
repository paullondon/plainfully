<?php declare(strict_types=1);

/**
 * Global configuration for Plainfully.
 *
 * Values are primarily read from environment variables (.env),
 * with safe fallbacks for local/dev use.
 */

return [
    // Application settings
    'app' => [
        'base_url' => getenv('APP_BASE_URL') ?: 'https://plainfully.com',
        'env'      => getenv('APP_ENV') ?: 'local',
        'css'      => 'A3-018',
    ],

    // Database connection (MariaDB / MySQL)
    'db' => [
        'dsn'  => getenv('DB_DSN')  ?: 'mysql:host=localhost;dbname=plainfully;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'plainfully_user',
        'pass' => getenv('DB_PASS') ?: '',
    ],

    // SMTP / PHPMailer (outbound)
    'smtp' => [
        'host'   => getenv('MAIL_HOST'),
        'port'   => (int)getenv('MAIL_PORT'),
        'secure' => getenv('MAIL_ENCRYPTION'), // 'tls' or 'ssl'
        'auth'   => true,

        'noreply_user'   => getenv('MAIL_NOREPLY_USER'),
        'noreply_pass'   => getenv('MAIL_NOREPLY_PASS'),

        'scamcheck_user' => getenv('MAIL_SCAMCHECK_USER'),
        'scamcheck_pass' => getenv('MAIL_SCAMCHECK_PASS'),

        'clarify_user'   => getenv('MAIL_CLARIFY_USER'),
        'clarify_pass'   => getenv('MAIL_CLARIFY_PASS'),
    ],

    // IMAP settings for the email bridge (inbound)
    'imap' => [
        'host'       => getenv('EMAIL_BRIDGE_IMAP_HOST'),
        'port'       => (int)getenv('EMAIL_BRIDGE_IMAP_PORT'),
        'encryption' => getenv('EMAIL_BRIDGE_IMAP_ENCRYPTION'),

        'scamcheck_user' => getenv('MAIL_SCAMCHECK_USER'),
        'scamcheck_pass' => getenv('MAIL_SCAMCHECK_PASS'),

        'clarify_user'   => getenv('MAIL_CLARIFY_USER'),
        'clarify_pass'   => getenv('MAIL_CLARIFY_PASS'),
    ],

    // Auth / magic-link settings
    'auth' => [
        'magic_link_ttl_minutes' => (int)(getenv('MAGIC_LINK_TTL_MINUTES') ?: 30),
    ],

    'debug' => [
        'magic_links' => (bool)(getenv('plainfully_debug_magic_links') ?: false),
    ],

    // Security options (Cloudflare Turnstile)
    'security' => [
        'turnstile_site_key'   => getenv('TURNSTILE_SITE_KEY')   ?: '',
        'turnstile_secret_key' => getenv('TURNSTILE_SECRET_KEY') ?: '',
    ],
];
