<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * AiClient
 *
 * Contract for analysis providers.
 *
 * IMPORTANT:
 * - Implementations MUST return an array.
 * - Preferred (new) shape:
 *     ['result_json' => '{...}']  // JSON string matching Plainfully v1 schema
 *   or
 *     ['result' => [...]]         // decoded array that will be wrapped into v1
 *
 * - Legacy shape is still accepted by CheckEngine (best-effort):
 *     ['short_verdict' => '...', 'capsule' => '...', 'is_scam' => bool]
 *
 * The optional $ctx allows plan-aware behaviour without changing call sites again.
 */
interface AiClient
{
    /**
     * @param string $text  Cleaned + capped message text
     * @param string $mode  'clarify'|'scamcheck'|'generic' (caller controlled)
     * @param array<string,mixed> $ctx Optional context (e.g. ['is_paid'=>true, 'source_type'=>'email'])
     *
     * @return array<string,mixed>
     */
    public function analyze(string $text, string $mode, array $ctx = []): array;
}
