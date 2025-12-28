<?php declare(strict_types=1);

namespace App\Features\Checks;

use PDO;
use Throwable;

/**
 * CheckEngine (Plainfully v1, DB-column tolerant)
 *
 * - Takes a CheckInput
 * - Calls AiClient
 * - Writes a row to `checks` (best-effort, fail-open)
 * - Returns CheckResult
 *
 * IMPORTANT:
 * - This version detects which columns exist in `checks` and only inserts those.
 *   This avoids fatal errors when your DB schema differs during MVP.
 */
final class CheckEngine
{
    private PDO $pdo;
    private AiClient $ai;

    /** @var array<string,bool>|null */
    private ?array $checksCols = null;

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

        // Legacy boolean for older columns
        $isScam = ($riskLevel === 'high');

        // Store (best effort). If DB write fails, still return result so UX works.
        $id = null;

        try {
            $id = $this->insertChecksRowBestEffort(
                $input,
                $isPaid,
                $isScam,
                $headline,
                $extTopicLine,
                $rawJson
            );
        } catch (Throwable $e) {
            // fail-open, but log
            error_log('CheckEngine DB insert failed (fail-open): ' . $e->getMessage());
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
     * Insert into `checks` using only columns that exist.
     * Returns inserted id or null.
     */
    private function insertChecksRowBestEffort(
        CheckInput $input,
        bool $isPaid,
        bool $isScam,
        string $headline,
        string $topicLine,
        ?string $rawJson
    ): ?int {
        $cols = $this->getChecksColumns();

        $insertCols = [];
        $params     = [];
        $values     = [];

        // Helper closure to add a column if it exists
        $add = function(string $col, string $param, $value) use (&$insertCols, &$params, &$values, $cols): void {
            if (!isset($cols[$col]) || $cols[$col] !== true) { return; }
            $insertCols[] = $col;
            $values[]     = $param;
            $params[$param] = $value;
        };

        $add('channel', ':channel', $input->channel);
        $add('source_identifier', ':source_identifier', $input->sourceIdentifier);
        $add('content_type', ':content_type', $input->contentType);

        // Your DB currently does NOT have `content` (per your error). Only add it if present.
        $add('content', ':content', $input->content);

        $add('is_scam', ':is_scam', $isScam ? 1 : 0);
        $add('is_paid', ':is_paid', $isPaid ? 1 : 0);

        // Legacy summary fields (worker UI uses these too)
        $add('short_verdict', ':short_verdict', $headline);
        $add('input_capsule', ':input_capsule', $topicLine);

        // New JSON field if you add it later
        $add('output_json', ':output_json', $rawJson);

        // created_at is sometimes present, sometimes auto. Only include if exists.
        if (isset($cols['created_at']) && $cols['created_at'] === true) {
            $insertCols[] = 'created_at';
            $values[] = 'NOW()';
        }

        if (count($insertCols) === 0) {
            return null;
        }

        $sql = 'INSERT INTO checks (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $values) . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $last = $this->pdo->lastInsertId();
        return is_string($last) && $last !== '' ? (int)$last : null;
    }

    /**
     * Cache the checks table columns (per process run).
     *
     * @return array<string,bool>
     */
    private function getChecksColumns(): array
    {
        if (is_array($this->checksCols)) {
            return $this->checksCols;
        }

        $cols = [];
        try {
            $stmt = $this->pdo->query('DESCRIBE checks');
            $rows = $stmt ? $stmt->fetchAll() : [];
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    $name = (string)($r['Field'] ?? '');
                    if ($name !== '') { $cols[$name] = true; }
                }
            }
        } catch (Throwable $e) {
            // fail-open, but keep empty
        }

        $this->checksCols = $cols;
        return $cols;
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
