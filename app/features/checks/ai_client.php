<?php
declare(strict_types=1);

namespace App\Features\Checks;

interface AiClientInterface
{
    public function classifyAndClarify(
        string $channel,
        string $content,
        string $contentType
    ): array;
}

final class DummyAiClient implements AiClientInterface
{
    public function classifyAndClarify(
        string $channel,
        string $content,
        string $contentType
    ): array {
        $length = mb_strlen($content);
        $isScam = $length > 50;

        return [
            'short_verdict' => $isScam
                ? '⚠️ Likely scam.'
                : '✅ No obvious scam indicators detected.',
            'long_report' => $isScam
                ? 'This long message was flagged as potentially risky (dummy AI).'
                : 'This message did not trigger any scam indicators (dummy AI).',
            'input_capsule' => mb_substr(trim(preg_replace('/\s+/', ' ', $content)), 0, 200),
            'is_scam' => $isScam,
            'upsell_flags' => $isScam ? ['consider_upgrade_plan'] : [],
            'model_metadata' => [
                'engine' => 'dummy',
                'version' => '0.1',
                'length' => $length
            ]
        ];
    }
}
