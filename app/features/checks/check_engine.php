<?php declare(strict_types=1);

namespace App\Features\Checks;

use PDO;
use Throwable;

/**
 * CheckEngine
 *
 * - Takes a CheckInput
 * - Calls AiClient (dummy for now)
 * - Writes a row to `checks`
 * - Returns CheckResult
 *
 * Security:
 * - Uses prepared statements only
 * - No dynamic SQL
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
        // Determine analysis mode by channel
        $mode = 'generic';
        if ($input->channel === 'email-scamcheck') {
            $mode = 'scamcheck';
        } elseif ($input->channel === 'email-clarify') {
            $mode = 'clarify';
        }

        $analysis = $this->ai->analyze($input->content, $mode);

        $shortVerdict = (string)($analysis['short_verdict'] ?? 'Unknown');
        $capsule      = (string)($analysis['capsule'] ?? '');
        $isScam       = (bool)($analysis['is_scam'] ?? false);

        // Store (best effort). If DB write fails, still return result so UX works.
        $id = null;

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
                ':short_verdict'     => $shortVerdict,
                ':input_capsule'     => $capsule,
            ]);

            $id = (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            error_log('CheckEngine DB insert failed (fail-open): ' . $e->getMessage());
        }

        return new CheckResult(
            $id,
            $shortVerdict,
            $capsule,
            $isScam,
            $isPaid,
            ['mode' => $mode]
        );
    }
}
