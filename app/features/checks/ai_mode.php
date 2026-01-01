<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * AiMode
 * Small enum to control analysis mode consistently.
 */
enum AiMode: string
{
    case Generic   = 'generic';
    case Clarify   = 'clarify';
    case Scamcheck = 'scamcheck';
}
