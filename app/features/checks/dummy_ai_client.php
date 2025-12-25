<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * Dummy AI client used during MVP while plumbing is built.
 * Produces deterministic(ish) output so the pipeline works end-to-end.
 */
final class DummyAiClient implements AiClient
{
    public function analyze(string $text, string $mode = 'generic'): array
    {
        $t = mb_strtolower($text);

        // Tiny heuristic: flag obvious scam language.
        $scamSignals = [
            'urgent', 'act now', 'verify', 'password', 'login', 'bank',
            'invoice', 'payment', 'wire', 'gift card', 'crypto',
            'suspended', 'security alert', 'click', 'link', 'otp', 'code'
        ];

        $hits = 0;
        foreach ($scamSignals as $w) {
            if (str_contains($t, $w)) {
                $hits++;
            }
        }

        $isScam = $mode === 'scamcheck' ? ($hits >= 1) : ($hits >= 3);

        $short = $isScam
            ? 'High likelihood of scam'
            : 'No obvious scam signs';

        // Keep capsule short and safe-ish (no HTML)
        $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $capsule = mb_substr($clean, 0, 300);

        if ($capsule === '') {
            $capsule = '(No content)';
        }

        return [
            'short_verdict' => $short,
            'capsule'       => $capsule,
            'is_scam'       => $isScam,
        ];
    }
}
