<?php declare(strict_types=1);

namespace App\Features\Checks;

use PDO;
use Throwable;

/**
 * CheckEngine (Plainfully v1)
 *
 * - Takes a CheckInput
 * - Calls AiClient
 * - Writes a row to `checks` (best-effort, fail-open)
 * - Returns CheckResult
 *
 * Security:
 * - Uses prepared statements only
 * - No dynamic SQL
 *
 * Storage strategy (MVP):
 * - Always stores the input content in `checks.content`
 * - Attempts to store the AI JSON in `checks.output_json` if the column exists.
 *   If it doesn't exist yet, it will silently fall back to the older insert shape.
 */
final class CheckEngine
{
    private PDO $pdo;
    private AiClient $ai;

    public function __construct(PDO $pdo, AiClient $ai)
    {
        $this->pdo = $pdo;
        $this->ai  = $ai;
    }

    public function run(CheckInput $input, bool $isPaid): CheckResult
    {
        // Determine analysis mode by channel (kept for compatibility with your routing)
        $mode = 'generic';
        if ($input->channel === 'email-scamcheck') {
            $mode = 'scamcheck';
        } elseif ($input->channel === 'email-clarify') {
            $mode = 'clarify';
        }

        // AiClient should return either:
        // - New structured format (array with key 'result_json' or key 'result')
        // - Or the legacy dummy format (short_verdict/capsule/is_scam)
        $analysis = $this->ai->analyze($input->content, $mode, ['is_paid' => $isPaid]);

        [$structured, $rawJson] = $this->coerceStructuredResult($analysis, $isPaid);

        // Derived fields used by worker/email rendering
        $status         = (string)($structured['result']['status'] ?? 'ok');
        $headline       = (string)($structured['result']['headline'] ?? 'Here’s a clear breakdown of this message');
        $riskLevel      = (string)($structured['result']['scam_risk_level'] ?? 'unable');
        $extRiskLine    = (string)($structured['result']['external_summary']['risk_line'] ?? 'Scam risk level: Unable to assess (checked)');
        $extTopicLine   = (string)($structured['result']['external_summary']['topic_line'] ?? 'There isn’t enough clear text to explain what this message means.');

        $webSays        = (string)($structured['result']['web']['what_the_message_says'] ?? 'The message doesn’t contain enough clear text for us to explain what it means.');
        $webAsks        = (string)($structured['result']['web']['what_its_asking_for'] ?? 'It’s not clear what is being asked for.');

        $webLevelLine   = (string)($structured['result']['web']['scam_risk']['level_line'] ?? 'Scam risk level: Unable to assess');
        $webLowNote     = (string)($structured['result']['web']['scam_risk']['low_level_note'] ?? 'We always scan for potential risks. The details below explain why this risk level was given.');
        $webExplain     = (string)($structured['result']['web']['scam_risk']['explanation'] ?? 'We always check for potential risks, but there wasn’t enough readable content to assess this one.');

        // Store (best effort). If DB write fails, still return result so UX works.
        $id = null;

        // Map a boolean "is_scam" for legacy columns
        $isScam = ($riskLevel === 'high');

        try {
            // Try new insert shape (includes output_json) first.
            $stmt = $this->pdo->prepare('
                INSERT INTO checks
                    (channel, source_identifier, content_type, content, is_scam, is_paid, short_verdict, input_capsule, output_json, created_at)
                VALUES
                    (:channel, :source_identifier, :content_type, :content, :is_scam, :is_paid, :short_verdict, :input_capsule, :output_json, NOW())
            ');

            $stmt->execute([
                ':channel'           => $input->channel,
                ':source_identifier' => $input->sourceIdentifier,
                ':content_type'      => $input->contentType,
                ':content'           => $input->content,
                ':is_scam'           => $isScam ? 1 : 0,
                ':is_paid'           => $isPaid ? 1 : 0,
                ':short_verdict'     => $headline,
                ':input_capsule'     => $extTopicLine,
                ':output_json'       => $rawJson,
            ]);

            $id = (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            // If output_json column doesn't exist yet, fall back to the old insert shape.
            try {
                $stmt = $this->pdo->prepare('
                    INSERT INTO checks
                        (channel, source_identifier, content_type, content, is_scam, is_paid, short_verdict, input_capsule, created_at)
                    VALUES
                        (:channel, :source_identifier, :content_type, :content, :is_scam, :is_paid, :short_verdict, :input_capsule, NOW())
                ');

                $stmt->execute([
                    ':channel'           => $input->channel,
                    ':source_identifier' => $input->sourceIdentifier,
                    ':content_type'      => $input->contentType,
                    ':content'           => $input->content,
                    ':is_scam'           => $isScam ? 1 : 0,
                    ':is_paid'           => $isPaid ? 1 : 0,
                    ':short_verdict'     => $headline,
                    ':input_capsule'     => $extTopicLine,
                ]);

                $id = (int)$this->pdo->lastInsertId();
            } catch (Throwable $e2) {
                error_log('CheckEngine DB insert failed (fail-open): ' . $e2->getMessage());
            }
        }

        return new CheckResult(
            $id,
            $status,
            $headline,
            $riskLevel,
            $extRiskLine,
            $extTopicLine,
            $webSays,
            $webAsks,
            $webLevelLine,
            $webLowNote,
            $webExplain,
            $isPaid,
            ['mode' => $mode],
            $rawJson
        );
    }

    /**
     * Coerce AiClient output into the new structured result format.
     *
     * Supports:
     * - ['result_json' => '{...json...}']  (preferred)
     * - ['result' => [...]]               (already decoded array)
     * - legacy: ['short_verdict'=>..., 'capsule'=>..., 'is_scam'=>...]
     *
     * @return array{0: array<string,mixed>, 1: string} (structured array, raw json string)
     */
    private function coerceStructuredResult(array $analysis, bool $isPaid): array
    {
        // Case 1: raw JSON provided
        if (isset($analysis['result_json']) && is_string($analysis['result_json'])) {
            $raw = $analysis['result_json'];
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return [$decoded, $raw];
            }
        }

        // Case 2: already decoded result provided
        if (isset($analysis['result']) && is_array($analysis['result'])) {
            $decoded = [
                'schema_version' => 'v1',
                'meta' => [
                    'ingestion' => [
                        'source_type' => 'email',
                        'received_at_utc' => gmdate('c'),
                    ],
                    'plan' => ['tier' => $isPaid ? 'unlimited' : 'free'],
                    'limits' => [
                        'input_truncated' => false,
                        'input_chars_used' => 0,
                        'input_chars_cap' => $isPaid ? 4000 : 1500,
                        'output_profile' => $isPaid ? 'paid_full' : 'free_compact',
                    ],
                ],
                'result' => $analysis['result'],
            ];
            $raw = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return [$decoded, is_string($raw) ? $raw : ''];
        }

        // Case 3: legacy mapping (best effort, still produces a valid v1-shaped object)
        $shortVerdict = (string)($analysis['short_verdict'] ?? 'Here’s a clear breakdown of this message');
        $capsule      = (string)($analysis['capsule'] ?? '');
        $isScam       = (bool)($analysis['is_scam'] ?? false);

        $riskLevel = $isScam ? 'high' : 'low';
        $headline  = $isScam ? 'This message is very likely not genuine' : 'Here’s a clear breakdown of this message';

        $decoded = [
            'schema_version' => 'v1',
            'meta' => [
                'ingestion' => [
                    'source_type' => 'email',
                    'received_at_utc' => gmdate('c'),
                ],
                'plan' => ['tier' => $isPaid ? 'unlimited' : 'free'],
                'limits' => [
                    'input_truncated' => false,
                    'input_chars_used' => 0,
                    'input_chars_cap' => $isPaid ? 4000 : 1500,
                    'output_profile' => $isPaid ? 'paid_full' : 'free_compact',
                ],
            ],
            'result' => [
                'status' => 'ok',
                'headline' => $headline,
                'scam_risk_level' => $riskLevel,
                'external_summary' => [
                    'risk_line' => ($riskLevel === 'high') ? 'Scam risk level: High (checked)' : 'Scam risk level: Low (checked)',
                    'topic_line' => $capsule !== '' ? $capsule : $shortVerdict,
                ],
                'web' => [
                    'what_the_message_says' => $capsule !== '' ? $capsule : $shortVerdict,
                    'what_its_asking_for' => 'It’s not clear what is being asked for.',
                    'scam_risk' => [
                        'level_line' => ($riskLevel === 'high') ? 'Scam risk level: High' : 'Scam risk level: Low',
                        'low_level_note' => ($riskLevel === 'low')
                            ? 'We always scan for potential risks. In this case, we have deemed it low risk.'
                            : 'We always scan for potential risks. The details below explain why this risk level was given.',
                        'explanation' => $isScam ? 'Some elements in the message suggest it may not be genuine.' : 'No strong risk signals were detected in the available text.',
                    ],
                ],
            ],
        ];

        $raw = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return [$decoded, is_string($raw) ? $raw : ''];
    }
}
