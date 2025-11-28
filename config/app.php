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
        'env'      => getenv('APP_ENV') ?: 'local', // 'Live' is fine, we just treat it as a label
        'css'      => '281125-003'
    ],

    // Database connection (MariaDB / MySQL)
    'db' => [
        'dsn'  => getenv('DB_DSN')  ?: 'mysql:host=localhost;dbname=plainfully;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'plainfully_user',
        'pass' => getenv('DB_PASS') ?: '',
    ],

    // Email settings (FROM details)
    'mail' => [
        // Prefer MAIL_FROM_EMAIL, fall back to MAIL_FROM, then a sane default
        'from_email' => getenv('MAIL_FROM_EMAIL')
            ?: (getenv('MAIL_FROM') ?: 'no-reply@plainfully.com'),
        'from_name'  => getenv('MAIL_FROM_NAME') ?: 'Plainfully',
    ],

    // SMTP / PHPMailer settings (IONOS-style, using MAIL_* vars)
    'smtp' => [
        'host'     => getenv('MAIL_HOST')       ?: 'localhost',
        'port'     => (int)(getenv('MAIL_PORT') ?: 25),
        'username' => getenv('MAIL_USER')       ?: '',
        'password' => getenv('MAIL_PASS')       ?: '',
        'secure'   => getenv('MAIL_ENCRYPTION') ?: '',   // '', 'tls', or 'ssl'
        'auth'     => true, // IONOS requires SMTP auth
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
