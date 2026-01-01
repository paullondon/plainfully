<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/features/checks/ai_mode.php
 * Purpose:
 *   Strongly-typed analysis modes for AiClient + CheckEngine.
 *
 * Change history:
 *   - 2026-01-01  Create AiMode enum
 * ============================================================
 */
enum AiMode: string
{
    case Clarify   = 'clarify';
    case Scamcheck = 'scamcheck';
    case Generic   = 'generic';

    /**
     * Safe converter for legacy/string callers.
     * Unknown values fall back to Generic.
     */
    public static function fromString(string $mode): self
    {
        $m = strtolower(trim($mode));
        return match ($m) {
            'clarify'   => self::Clarify,
            'scamcheck' => self::Scamcheck,
            default     => self::Generic,
        };
    }
}
