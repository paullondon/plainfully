<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * DummyAiClient
 *
 * MVP placeholder that returns a v1-compatible structured result.
 * This lets you wire end-to-end storage + rendering before the real API is in.
 */
final class DummyAiClient implements AiClient
{
    public function analyze(string $text, string $mode, array $ctx = []): array
    {
        $isPaid = (bool)($ctx['is_paid'] ?? false);

        // Very small, deterministic dummy behaviour.
        $risk = 'low';
        $headline = 'Here’s a clear breakdown of this message';

        // Simple heuristic to make testing easier (NOT real detection).
        $t = mb_strtolower($text, 'UTF-8');
        if (str_contains($t, 'password') || str_contains($t, 'verify') || str_contains($t, 'urgent') || str_contains($t, 'bank')) {
            $risk = 'medium';
            $headline = 'This message may carry some risk';
        }
        if (str_contains($t, 'gift card') || str_contains($t, 'crypto') || str_contains($t, 'wallet') || str_contains($t, 'action required immediately')) {
            $risk = 'high';
            $headline = 'This message is very likely not genuine';
        }

        $topicLine = 'This looks like a general message that is asking for your attention.';
        if ($mode === 'scamcheck') {
            $topicLine = 'This message is being checked mainly for scam risk.';
        } elseif ($mode === 'clarify') {
            $topicLine = 'This message is being clarified in plain English.';
        }

        $riskLineExternal = 'Scam risk level: ' . ucfirst($risk) . ' (checked)';
        $riskLineWeb      = 'Scam risk level: ' . ucfirst($risk === 'unable' ? 'Unable to assess' : $risk);

        $lowNote = ($risk === 'low')
            ? 'We always scan for potential risks. In this case, we have deemed it low risk.'
            : 'We always scan for potential risks. The details below explain why this risk level was given.';

        $explain = match ($risk) {
            'high'   => 'Some parts of the message match common scam patterns.',
            'medium' => 'Some parts of the message look unusual or time-pressured.',
            default  => 'No strong risk signals were detected in the available text.',
        };

        // Treat very short content as unreadable for realism.
        $trimmed = trim($text);
        $status = (mb_strlen($trimmed, 'UTF-8') < 20) ? 'unreadable' : 'ok';
        if ($status === 'unreadable') {
            $risk = 'unable';
            $headline = 'We couldn’t clearly read this message';
            $riskLineExternal = 'Scam risk level: Unable to assess (checked)';
            $riskLineWeb      = 'Scam risk level: Unable to assess';
            $topicLine = 'There isn’t enough clear text to explain what this message means.';
            $lowNote   = 'We always scan for potential risks. The details below explain why this risk level was given.';
            $explain   = 'We always check for potential risks, but there wasn’t enough readable content to assess this one.';
        }

        $result = [
            'status' => $status,
            'headline' => $headline,
            'scam_risk_level' => $risk,
            'external_summary' => [
                'risk_line' => $riskLineExternal,
                'topic_line' => $topicLine,
            ],
            'web' => [
                'what_the_message_says' => ($status === 'unreadable')
                    ? 'The message doesn’t contain enough clear text for us to explain what it means. This can happen if the content is very short, heavily formatted, or cut off.'
                    : ($isPaid ? 'This is a placeholder clarification until the real AI is connected.' : 'This is a short placeholder clarification.'),
                'what_its_asking_for' => ($status === 'unreadable')
                    ? 'It’s not clear what is being asked for.'
                    : 'It is not clear what is being asked for.',
                'scam_risk' => [
                    'level_line' => $riskLineWeb,
                    'low_level_note' => $lowNote,
                    'explanation' => $explain,
                ],
            ],
        ];

        // Return decoded (CheckEngine will wrap into v1 and store rawJson)
        return ['result' => $result];
    }
}
