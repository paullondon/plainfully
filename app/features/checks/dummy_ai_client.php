<?php declare(strict_types=1);

namespace App\Features\Checks;

use Throwable;

/**
 * DummyAiClient
 *
 * Dev stub that returns a predictable v1-shaped payload.
 * MUST match AiClient interface:
 *   analyze(string $text, string $mode, array $ctx = []): array
 */
final class DummyAiClient implements AiClient
{
    /**
     * @param string $text  Cleaned + capped message text
     * @param string $mode  'clarify'|'scamcheck'|'generic'
     * @param array<string,mixed> $ctx
     *
     * @return array<string,mixed>
     */
    public function analyze(string $text, string $mode, array $ctx = []): array
    {
        try {
            $mode = strtolower(trim($mode));
            if (!in_array($mode, ['clarify', 'scamcheck', 'generic'], true)) {
                $mode = 'generic';
            }

            // Super simple stub behaviour
            $isScam = ($mode === 'scamcheck') ? false : false;

            $headline = ($mode === 'clarify')
                ? 'Clarified'
                : (($mode === 'scamcheck') ? 'Checked' : 'Processed');

            // Keep the capsule short and user-friendly
            $capsule = $this->makeCapsule($text);

            return [
                'status' => 'ok',
                'headline' => $headline,
                'external_risk_line' => $isScam ? 'Scam risk level: high' : 'Scam risk level: low',
                'external_topic_line' => $capsule,
                'scam_risk_level' => $isScam ? 'high' : 'low',
                'is_scam' => $isScam,

                // Website fields (optional, but present)
                'web_what_the_message_says' => $capsule,
                'web_what_its_asking_for' => '',
                'web_scam_level_line' => $isScam ? 'High scam risk' : 'Low scam risk',
                'web_low_risk_note' => $isScam ? '' : 'No major red flags detected in this stub response.',
                'web_scam_explanation' => $isScam ? 'This is a dummy stub; no real scam analysis performed.' : '',
            ];
        } catch (Throwable $e) {
            // Fail-open: return minimum safe shape
            error_log('DummyAiClient failed: ' . $e->getMessage());
            return [
                'status' => 'ok',
                'headline' => 'Your result is ready',
                'external_risk_line' => 'Scam risk level: unknown',
                'external_topic_line' => 'This message appears to be about: unknown',
                'scam_risk_level' => 'low',
                'is_scam' => false,
            ];
        }
    }

    private function makeCapsule(string $text): string
    {
        $t = trim(preg_replace("/\s+/", ' ', $text) ?? '');
        if ($t === '') {
            return 'This message appears to be about: unknown';
        }

        // Take first ~120 chars as a simple “topic line”
        if (mb_strlen($t, 'UTF-8') > 120) {
            $t = rtrim(mb_substr($t, 0, 120, 'UTF-8')) . '…';
        }

        return 'This message appears to be about: ' . $t;
    }
}
