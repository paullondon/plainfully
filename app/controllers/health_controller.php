<?php declare(strict_types=1);

/**
 * Health controller for Plainfully
 */

function handle_health(array $config): void
{
    $debugToken = getenv('DEBUG_TOKEN') ?: '';
    $token      = $_GET['token'] ?? '';

    if ($debugToken === '' || $token !== $debugToken) {
        http_response_code(404);

        ob_start();
        require __DIR__ . '/../views/errors/404.php';
        $inner = ob_get_clean();

        pf_render_shell('Not found', $inner);
        return;
    }

    $status = [
        'app_env'   => $config['app']['env'] ?? 'unknown',
        'db_ok'     => false,
        'turnstile' => [
            'site_key_set'   => !empty($config['security']['turnstile_site_key']),
            'secret_set'     => !empty($config['security']['turnstile_secret_key']),
        ],
        'mail'      => [
            'from_email_set' => !empty($config['mail']['from_email']),
            'smtp_host_set'  => !empty($config['smtp']['host']),
        ],
    ];

    try {
        $pdo = pf_db();
        $pdo->query('SELECT 1');
        $status['db_ok'] = true;
    } catch (Throwable $e) {
        $status['db_ok'] = false;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($status, JSON_PRETTY_PRINT);
}
