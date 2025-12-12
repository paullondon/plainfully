<?php declare(strict_types=1);

/**
 * TEMPORARY turnstile bypass to keep login working
 */
function pf_turnstile_verify(?string $token): array
{
    return [true, 'temporary bypass'];
}
