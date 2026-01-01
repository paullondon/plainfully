<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * AiClient
 *
 * Contract for analysis providers.
 */
interface AiClient
{
    /**
     * @param string $text  Cleaned + capped message text
     * @param AiMode $mode  Generic|Clarify|Scamcheck
     * @param array<string,mixed> $ctx Optional context
     *
     * @return array<string,mixed>
     */
    public function analyze(string $text, AiMode $mode, array $ctx = []): array;
}
