<?php
declare(strict_types=1);

namespace App\Features\Checks;

use PDO;
use Exception;

final class CheckEngine
{
    private PDO $db;
    private AiClientInterface $aiClient;
    private int $maxContentLength = 4000;

    public function __construct(PDO $db, AiClientInterface $aiClient)
    {
        $this->db = $db;
        $this->aiClient = $aiClient;

        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function run(CheckInput $input, bool $isPaid): CheckResult
    {
        $this->enforceInputSafety($input);

        $userId = $this->findOrCreateUser($input);

        $aiPayload = $this->runAi($input);

        $checkId = $this->storeCheck($userId, $input, $aiPayload, $isPaid);

        return new CheckResult(
            $checkId,
            $aiPayload['short_verdict'],
            $aiPayload['long_report'],
            $aiPayload['input_capsule'],
            $aiPayload['is_scam'],
            $isPaid,
            $aiPayload['upsell_flags']
        );
    }

    private function enforceInputSafety(CheckInput $input): void
{
    // 1) Normalise whitespace + line endings
    $normalized = str_replace(["\r\n", "\r"], "\n", $input->rawContent);
    $normalized = preg_replace('/[ \t]+/u', ' ', (string)$normalized);
    $normalized = trim($normalized);

    if ($normalized === '') {
        throw new Exception('Please paste something to check.');
    }

    // 2) Hard length cap (protects AI + DB)
    if (mb_strlen($normalized) > $this->maxContentLength) {
        $normalized = mb_substr($normalized, 0, $this->maxContentLength);
    }

    // 3) URL extraction + stripping (neutralise links before AI)
    //    Match:
    //      - http://something
    //      - https://something
    //      - www.something.tld/...
    $urlPattern = '/\b((https?:\/\/|www\.)[a-z0-9\-]+(\.[a-z0-9\-]+)+[^\s]*)/iu';
    $urlCount   = 0;

    $normalized = preg_replace_callback(
        $urlPattern,
        static function (array $matches) use (&$urlCount): string {
            $urlCount++;
            return '[link]';
        },
        $normalized
    );


    // 4) Offensive word redaction using external config
    static $cachedFilters = null;

    if ($cachedFilters === null) {
        // __DIR__ = /httpdocs/app/features/checks
        // dirname(__DIR__, 3) = /httpdocs (project root)
        $configPathRoot = dirname(__DIR__, 3) . '/config/word_filters.php';

        if (is_readable($configPathRoot)) {
            $cachedFilters = require $configPathRoot;
        } else {
            $cachedFilters = [];
        }
    }

    $badWords = $cachedFilters;

    foreach ($badWords as $word => $mask) {
        $normalized = preg_replace(
            '/' . preg_quote($word, '/') . '/iu',
            $mask,
            $normalized
        );
    }



    // 5) Lightweight spam heuristic (not yet blocking – just ready for future use)
    $len = mb_strlen($normalized);
    $uppercaseRatio = 0.0;

    if ($len > 0) {
        $upperOnly = preg_replace('/[^A-Z]/u', '', $normalized);
        if ($upperOnly !== '' && $len > 0) {
            $uppercaseRatio = mb_strlen($upperOnly) / $len;
        }
    }

    // Example: if you ever want to *block* obvious rubbish, we can uncomment this.
    // For now we just let AI handle it.
    //
    // if ($urlCount > 10 || $uppercaseRatio > 0.9) {
    //     throw new Exception('Message looks like automated spam. Please send a smaller, clearer snippet.');
    // }

    // 6) Write back the safe, normalised text
    $input->rawContent = $normalized;
}


    private function findOrCreateUser(CheckInput $input): int
    {
        // Email priority
        if ($input->email) {
            if ($id = $this->findByField("email", $input->email)) return $id;
        }

        // Phone next
        if ($input->phone) {
            if ($id = $this->findByField("phone", $input->phone)) return $id;
        }

        $stmt = $this->db->prepare("
            INSERT INTO users (email, phone, created_at)
            VALUES (:email, :phone, NOW())
        ");

        $stmt->execute([
            ':email' => $input->email,
            ':phone' => $input->phone
        ]);

        return (int)$this->db->lastInsertId();
    }

    private function findByField(string $field, string $value): ?int
    {
        $sql = "SELECT id FROM users WHERE $field = :v LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':v' => $value]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r ? (int)$r['id'] : null;
    }

    private function runAi(CheckInput $input): array
    {
        try {
            return $this->aiClient->classifyAndClarify(
                $input->channel,
                $input->rawContent,
                $input->contentType
            );
        } catch (Exception $e) {
            return [
                'short_verdict' => '⚠️ AI failed.',
                'long_report' => 'AI unavailable. No raw content stored.',
                'input_capsule' => mb_substr($input->rawContent, 0, 200),
                'is_scam' => false,
                'upsell_flags' => ['ai_unavailable'],
                'model_metadata' => ['error' => $e->getMessage()]
            ];
        }
    }

    private function storeCheck(
        int $userId,
        CheckInput $input,
        array $ai,
        bool $isPaid
    ): int {
        $aiJson = json_encode($ai, JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare("
            INSERT INTO checks (
                user_id,
                channel,
                source_identifier,
                content_type,
                ai_result_json,
                short_summary,
                is_scam,
                is_paid,
                created_at
            ) VALUES (
                :u,
                :c,
                :s,
                :t,
                :json,
                :summary,
                :scam,
                :paid,
                NOW()
            )
        ");

        $stmt->execute([
            ':u' => $userId,
            ':c' => $input->channel,
            ':s' => $input->sourceIdentifier,
            ':t' => $input->contentType,
            ':json' => $aiJson,
            ':summary' => mb_substr($ai['input_capsule'], 0, 255),
            ':scam' => $ai['is_scam'] ? 1 : 0,
            ':paid' => $isPaid ? 1 : 0,
        ]);

        return (int)$this->db->lastInsertId();
    }
}
