<?php declare(strict_types=1);

/**
 * Global configuration for Plainfully.
 * Adjust these values for your environment.
 */

return [
    // Application settings
    'app' => [
    'base_url' => getenv('APP_BASE_URL') ?: 'https://plainfully.example',
    'env' => getenv('APP_ENV') ?: 'local',
    ],
    'db' => [
        'dsn'  => getenv('DB_DSN'),
        'user' => getenv('DB_USER'),
        'pass' => getenv('DB_PASS'),
    ],
    'mail' => [
        'from_email' => getenv('MAIL_FROM_EMAIL'),
        'from_name'  => getenv('MAIL_FROM_NAME'),
        'host'       => getenv('MAIL_HOST') ?: 'smtp.ionos.co.uk',
        'port'       => (int)(getenv('MAIL_PORT') ?: 587),
        'user'       => getenv('MAIL_USER') ?: '',
        'pass'       => getenv('MAIL_PASS') ?: '',
        'encryption' => getenv('MAIL_ENCRYPTION') ?: 'tls', // tls or ssl
    ],
    'auth' => [
        'magic_link_ttl_minutes' => (int)(getenv('MAGIC_LINK_TTL_MINUTES') ?: 30),
    ],
    'captcha' => [
    'turnstile_site_key'   => getenv('TURNSTILE_SITE_KEY')   ?: '',
    'turnstile_secret_key' => getenv('TURNSTILE_SECRET_KEY') ?: '',
    ],
];
