<?php declare(strict_types=1);

echo 'Clarification stored. ID = ' . htmlspecialchars($_GET['id'] ?? '', ENT_QUOTES, 'UTF-8');
