<?php declare(strict_types=1);

namespace App\Features\Lifecycle;

use PDO;
use Throwable;

/**
 * LifecycleEngine
 *
 * Purpose:
 * - Decouple lifecycle emails from ingestion/registration
 * - Send calm, scheduled messages based on user state
 *
 * Security:
 * - Prepared statements only
 * - Idempotent flags (send-once markers)
 * - Per-user failure isolation (one bad address doesn't break the run)
 */
final class LifecycleEngine
{
    private PDO $pdo;
    private LifecycleMailer $mailer;

    public function __construct(PDO $pdo)
    {
        $this->pdo    = $pdo;
        $this->mailer = new LifecycleMailer();
    }

    /**
     * Run lifecycle processing for up to $limit users.
     *
     * @param int $limit Max users processed per cron run (rate limiting).
     */
    public function run(int $limit = 50): void
    {
        // Ensure we have flags rows for newly created users.
        $this->ensureFlagsRows($limit);

        // 1) Welcome (Day 0)
        $this->sendWelcome($limit);

        // 2) Hints & tips (Day 3)
        $this->sendTips($limit);

        // 3) Under-use reminder (Day 10, only if unused free allowance + not paid)
        $this->sendUnderusePrompt($limit);

        // 4) Feedback request (Day 20)
        $this->sendDay20Feedback($limit);

        // 5) Single-day token follow-up (near expiry)
        $this->sendSingleDayFollowup($limit);

        // 6) Direct-debit unlimited check-in (10–20 days after first DD upgrade)
        $this->sendDirectDebitCheckin($limit);

        // 7) Monthly “Clarification Engine Review” (every 28 days, DD unlimited only)
        $this->sendDirectDebitMonthlyReview($limit);
    }

    // ---------------------------------------------------------------------
    // Flags seeding (idempotent)
    // ---------------------------------------------------------------------

    private function ensureFlagsRows(int $limit): void
    {
        $sql = "
            INSERT IGNORE INTO user_lifecycle_flags (user_id)
            SELECT u.id
            FROM users u
            LEFT JOIN user_lifecycle_flags f ON f.user_id = u.id
            WHERE f.user_id IS NULL
            ORDER BY u.id DESC
            LIMIT :lim
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
    }

    // ---------------------------------------------------------------------
    // Email senders
    // ---------------------------------------------------------------------

    private function sendWelcome(int $limit): void
    {
        $sql = "
            SELECT u.id, u.email
            FROM users u
            JOIN user_lifecycle_flags f ON f.user_id = u.id
            WHERE f.welcome_sent_at IS NULL
            ORDER BY u.id ASC
            LIMIT :lim
        ";

        $this->sendBatch($sql, $limit, function (int $userId, string $email): void {
            $this->mailer->sendWelcome($email);

            $upd = $this->pdo->prepare("
                UPDATE user_lifecycle_flags
                SET welcome_sent_at = NOW()
                WHERE user_id = :uid
            ");
            $upd->execute([':uid' => $userId]);
        });
    }

    private function sendTips(int $limit): void
    {
        $sql = "
            SELECT u.id, u.email
            FROM users u
            JOIN user_lifecycle_flags f ON f.user_id = u.id
            WHERE f.tips_sent_at IS NULL
              AND u.created_at <= (NOW() - INTERVAL 3 DAY)
            ORDER BY u.id ASC
            LIMIT :lim
        ";

        $this->sendBatch($sql, $limit, function (int $userId, string $email): void {
            $this->mailer->sendTips($email);

            $upd = $this->pdo->prepare("
                UPDATE user_lifecycle_flags
                SET tips_sent_at = NOW()
                WHERE user_id = :uid
            ");
            $upd->execute([':uid' => $userId]);
        });
    }

    private function sendUnderusePrompt(int $limit): void
    {
        /**
         * Definition (MVP):
         * - User has at least one FREE token that has not expired yet
         * - User does NOT have any active PAID token (day or unlimited)
         * - It's been 10+ days since signup
         */
        $sql = "
            SELECT u.id, u.email
            FROM users u
            JOIN user_lifecycle_flags f ON f.user_id = u.id
            WHERE f.underuse_prompt_sent_at IS NULL
              AND u.created_at <= (NOW() - INTERVAL 10 DAY)
              AND EXISTS (
                  SELECT 1 FROM user_tokens t
                  WHERE t.user_id = u.id
                    AND t.type = 'free'
                    AND (t.expires_at IS NULL OR t.expires_at > NOW())
              )
              AND NOT EXISTS (
                  SELECT 1 FROM user_tokens t2
                  WHERE t2.user_id = u.id
                    AND t2.type IN ('day','unlimited')
                    AND (t2.expires_at IS NULL OR t2.expires_at > NOW())
              )
            ORDER BY u.id ASC
            LIMIT :lim
        ";

        $this->sendBatch($sql, $limit, function (int $userId, string $email): void {
            $this->mailer->sendUnderusePrompt($email);

            $upd = $this->pdo->prepare("
                UPDATE user_lifecycle_flags
                SET underuse_prompt_sent_at = NOW()
                WHERE user_id = :uid
            ");
            $upd->execute([':uid' => $userId]);
        });
    }

    private function sendDay20Feedback(int $limit): void
    {
        $sql = "
            SELECT u.id, u.email
            FROM users u
            JOIN user_lifecycle_flags f ON f.user_id = u.id
            WHERE f.feedback_sent_at IS NULL
              AND u.created_at <= (NOW() - INTERVAL 20 DAY)
            ORDER BY u.id ASC
            LIMIT :lim
        ";

        $this->sendBatch($sql, $limit, function (int $userId, string $email): void {
            $this->mailer->sendDay20Feedback($email, $userId);

            $upd = $this->pdo->prepare("
                UPDATE user_lifecycle_flags
                SET feedback_sent_at = NOW()
                WHERE user_id = :uid
            ");
            $upd->execute([':uid' => $userId]);
        });
    }

    private function sendSingleDayFollowup(int $limit): void
    {
        /**
         * Trigger:
         * - Their most recent DAY token expires within 24 hours (or already expired within last 12h)
         * - They do NOT have an active unlimited token
         * - We haven't sent the follow-up before
         */
        $sql = "
            SELECT u.id, u.email
            FROM users u
            JOIN user_lifecycle_flags f ON f.user_id = u.id
            WHERE f.single_day_followup_sent_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM user_tokens tu
                  WHERE tu.user_id = u.id
                    AND tu.type = 'unlimited'
                    AND (tu.expires_at IS NULL OR tu.expires_at > NOW())
              )
              AND EXISTS (
                  SELECT 1 FROM user_tokens td
                  WHERE td.user_id = u.id
                    AND td.type = 'day'
                    AND td.expires_at IS NOT NULL
                    AND td.expires_at BETWEEN (NOW() - INTERVAL 12 HOUR) AND (NOW() + INTERVAL 24 HOUR)
              )
            ORDER BY u.id ASC
            LIMIT :lim
        ";

        $this->sendBatch($sql, $limit, function (int $userId, string $email): void {
            // MVP: offer another day “on us” + calm 99p next-27-days offer (optional CTA)
            $this->mailer->sendSingleDayFollowup($email, $userId);

            $upd = $this->pdo->prepare("
                UPDATE user_lifecycle_flags
                SET single_day_followup_sent_at = NOW()
                WHERE user_id = :uid
            ");
            $upd->execute([':uid' => $userId]);
        });
    }

    private function sendDirectDebitCheckin(int $limit): void
    {
        /**
         * Trigger:
         * - First unlimited token purchased via direct debit was 10–20 days ago
         * - Send once only
         */
        $sql = "
            SELECT u.id, u.email
            FROM users u
            JOIN user_lifecycle_flags f ON f.user_id = u.id
            WHERE f.dd_checkin_sent_at IS NULL
              AND EXISTS (
                  SELECT 1
                  FROM user_tokens t
                  WHERE t.user_id = u.id
                    AND t.type = 'unlimited'
                    AND t.source = 'direct_debit'
                    AND t.created_at BETWEEN (NOW() - INTERVAL 20 DAY) AND (NOW() - INTERVAL 10 DAY)
              )
            ORDER BY u.id ASC
            LIMIT :lim
        ";

        $this->sendBatch($sql, $limit, function (int $userId, string $email): void {
            $this->mailer->sendDirectDebitCheckin($email, $userId);

            $upd = $this->pdo->prepare("
                UPDATE user_lifecycle_flags
                SET dd_checkin_sent_at = NOW()
                WHERE user_id = :uid
            ");
            $upd->execute([':uid' => $userId]);
        });
    }

    private function sendDirectDebitMonthlyReview(int $limit): void
    {
        /**
         * Trigger:
         * - User has an active unlimited token via direct debit
         * - Last monthly review was 28+ days ago (or never)
         */
        $sql = "
            SELECT u.id, u.email
            FROM users u
            JOIN user_lifecycle_flags f ON f.user_id = u.id
            WHERE EXISTS (
                SELECT 1 FROM user_tokens t
                WHERE t.user_id = u.id
                  AND t.type = 'unlimited'
                  AND t.source = 'direct_debit'
                  AND (t.expires_at IS NULL OR t.expires_at > NOW())
            )
            AND (f.dd_monthly_review_sent_at IS NULL OR f.dd_monthly_review_sent_at <= (NOW() - INTERVAL 28 DAY))
            ORDER BY u.id ASC
            LIMIT :lim
        ";

        $this->sendBatch($sql, $limit, function (int $userId, string $email): void {
            $this->mailer->sendMonthlyReview($email, $userId);

            $upd = $this->pdo->prepare("
                UPDATE user_lifecycle_flags
                SET dd_monthly_review_sent_at = NOW()
                WHERE user_id = :uid
            ");
            $upd->execute([':uid' => $userId]);
        });
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Batch-send helper.
     *
     * @param string   $sql      Query that returns (id, email)
     * @param int      $limit    Row limit
     * @param callable $perUser  function(int $userId, string $email): void
     */
    private function sendBatch(string $sql, int $limit, callable $perUser): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return;
        }

        foreach ($rows as $r) {
            $userId = (int)($r['id'] ?? 0);
            $email  = (string)($r['email'] ?? '');

            if ($userId <= 0 || $email === '') {
                continue;
            }

            try {
                $perUser($userId, $email);
            } catch (Throwable $e) {
                // Per-user failure isolation: mark nothing, retry next run.
                error_log('[lifecycle_engine] user ' . $userId . ' failed: ' . $e->getMessage());
            }
        }
    }
}

