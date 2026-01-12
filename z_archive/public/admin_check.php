<?php declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';      // loads $config + starts session in your setup
require_once __DIR__ . '/../app/auth/login.php';    // pf_is_admin()

header('Content-Type: text/plain; charset=utf-8');

echo "session user_email: " . ($_SESSION['user_email'] ?? '(none)') . "\n";
echo "env ADMIN_EMAILS: " . (getenv('ADMIN_EMAILS') ?: '(missing)') . "\n";
echo "pf_is_admin(): " . (pf_is_admin() ? 'YES' : 'NO') . "\n";
