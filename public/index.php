<?php declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require dirname(__DIR__) . '/bootstrap/app.php';