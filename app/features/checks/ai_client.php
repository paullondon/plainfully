<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * AI client abstraction.
 *
 * For MVP you’re using DummyAiClient, but keeping an interface means you can
 * swap in a real provider later without rewriting CheckEngine.
 */
interface AiClient
{
    /**
     * Analyze text and return a structured array.
     *
     * Expected keys (MVP):
     * - short_verdict (string)
     * - capsule (string)
     * - is_scam (bool)
     *
     * @return array<string,mixed>
     */
    public function analyze(string $text, string $mode = 'generic'): array;
}
