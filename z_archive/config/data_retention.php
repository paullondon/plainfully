<?php declare(strict_types=1);

return [
    // Tables whose rows are eligible for deletion based on expires_at
    'purge_tables' => [
        'clarification_uploads',
        'clarification_details',
        'clarifications',
        'magic_login_tokens',
        'auth_events',
        // 'api_logs',
        // 'rate_limit_logs',
    ],

    // Tables that are explicitly exempt for legal / financial reasons
    'financial_tables' => [
        'payments',
        'payment_intents',
        'invoices',
        'subscriptions',
        'payouts',
    ],
];
