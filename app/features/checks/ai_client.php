<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/features/checks/ai_client.php
 * Purpose: Contract for analysis providers.
 * Change history:
 *   - 2026-01-01: Switched $mode from string to AiMode enum for safety.
 * ============================================================
 */
interface AiClient
{
    /**
     * @param string $text  Cleaned + capped message text
     * @param AiMode $mode  Scamcheck|Clarify|Generic
     * @param array<string,mixed> $ctx Optional context
     *
     * @return array<string,mixed>
     */
    public function analyze(string $text, AiMode $mode, array $ctx = []): array;
}
