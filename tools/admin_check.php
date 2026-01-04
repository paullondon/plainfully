<?php declare(strict_types=1);

require_once __DIR__ . '/../app/support/db.php';
require_once __DIR__ . '/../app/auth/login.php'; // where pf_is_admin() lives

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

header('Content-Type: text/plain; charset=utf-8');

echo "session user_email: " . ($_SESSION['user_email'] ?? '(none)') . "\n";
echo "env ADMIN_EMAILS: " . (getenv('ADMIN_EMAILS') ?: '(missing)') . "\n";
echo "pf_is_admin(): " . (pf_is_admin() ? 'YES' : 'NO') . "\n";
echo "pf_user_email(): " . pf_user_email() . "\n";