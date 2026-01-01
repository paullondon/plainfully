<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * AiMode
 *
 * Strict set of allowed AI analysis modes.
 * Prevents typos like "scam-check" vs "scamcheck".
 */
enum AiMode: string
{
    case Clarify   = 'clarify';
    case Scamcheck = 'scamcheck';
    case Generic   = 'generic';
}