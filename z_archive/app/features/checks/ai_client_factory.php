<?php declare(strict_types=1);

// No composer: load concrete classes safely (order matters)
require_once __DIR__ . '/ai_mode.php';
require_once __DIR__ . '/ai_client.php';
require_once __DIR__ . '/dummy_ai_client.php';

/**
 * Build the AI client implementation.
 *
 * Rules:
 * - Fail-open to Dummy in non-live envs (so the app still runs)
 * - Fail-closed to Dummy even in live for now (until real client is implemented)
 *   to avoid accidental paid calls / misconfig issues.
 */
if (!function_exists('pf_ai_client')) {
    function pf_ai_client(): \App\Features\Checks\AiClient
    {
        $env = strtolower((string)(getenv('APP_ENV') ?: 'local'));

        // Explicit debug flag forces Dummy
        $debug = strtolower((string)(getenv('PLAINFULLY_DEBUG') ?: ''));
        $forceDummy = in_array($debug, ['1', 'true', 'yes', 'on'], true);

        if ($forceDummy) {
            return new \App\Features\Checks\DummyAiClient();
        }

        // TODO (later): return new OpenAiClient(...) when implemented.
        // For MVP: always Dummy to keep behaviour predictable and cost-safe.
        return new \App\Features\Checks\DummyAiClient();
    }
}
