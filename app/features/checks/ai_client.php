<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully - AiClient Contract
 * ============================================================
 * File: app/features/checks/ai_client.php
 *
 * Purpose:
 *   Defines the contract for AI analysis providers used by
 *   CheckEngine.
 *
 * Design notes:
 *   - This file is intentionally minimal.
 *   - It does NOT decide whether a real AI or Dummy AI is used.
 *   - Selection is controlled elsewhere via ENV:
 *
 *       PLAINFULLY_DEBUG=true|false
 *
 *     When:
 *       - false -> Real AI client should be injected
 *       - true  -> DummyAiClient is injected for safe debugging
 *
 *   This keeps:
 *     - CheckEngine deterministic
 *     - Debug behaviour explicit
 *     - Production safe by default
 *
 * This file should almost never change.
 * ============================================================
 */

namespace App\Features\Checks;

interface AiClient
{
    /**
     * Analyse cleaned user text.
     *
     * @param string $text  Normalised message content
     * @param AiMode $mode  Generic | Clarify
     * @param array<string,mixed> $ctx Optional execution context
     *
     * @return array<string,mixed> Normalised analysis payload
     */
    public function analyze(string $text, AiMode $mode, array $ctx = []): array;
}
