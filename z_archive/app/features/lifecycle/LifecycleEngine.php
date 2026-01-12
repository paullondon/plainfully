<?php declare(strict_types=1);

namespace App\Features\Lifecycle;

use PDO;
use Throwable;

final class LifecycleEngine
{
    private PDO $pdo;
    private LifecycleMailer $mailer;

    public function __construct(PDO $pdo)
    {
        $this->pdo    = $pdo;
        $this->mailer = new LifecycleMailer();
    }

    public function run(int $limit = 50): void
    {
        $this->ensureFlagsRows($limit);

        $this->sendWelcome($limit);
        $this->sendTips($limit);
        $this->sendUnderusePrompt($limit);
        $this->sendDay20Feedback($limit);
        $this->sendSingleDayFollowup($limit);
        $this->sendDirectDebitCheckin($limit);
        $this->sendDirectDebitMonthlyReview($limit);
    }

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

    private function sendBatch(string $sql, int $limit, callable $perUser): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { return; }

        foreach ($rows as $row) {
            $userId = (int)($row['id'] ?? 0);
            $email  = (string)($row['email'] ?? '');

            if ($userId <= 0 || $email === '') { continue; }

            try {
                $perUser($userId, $email);
            } catch (Throwable $e) {
                $msg = strtolower($e->getMessage());

                // DB sanitisation: delete only on strong permanent mailbox errors.
                if ($this->isPermanentMailboxFailure($msg)) {
                    $this->deleteUser($userId);
                    error_log('[lifecycle_engine] deleted user ' . $userId . ' (mailbox invalid)');
                    continue;
                }

                error_log('[lifecycle_engine] user ' . $userId . ' failed: ' . $e->getMessage());
            }
        }
    }

    private function isPermanentMailboxFailure(string $msg): bool
    {
        // DO NOT include 5.7.1 (policy) here — that can be real users.
        $patterns = [
            '5.1.1',
            'unknown user',
            'no such user',
            'user does not exist',
            'mailbox unavailable',
            'recipient address rejected',
            'address not found',
        ];

        foreach ($patterns as $p) {
            if (str_contains($msg, $p)) {
                return true;
            }
        }
        return false;
    }

    private function deleteUser(int $userId): void
    {
        $del = $this->pdo->prepare("DELETE FROM users WHERE id = :id");
        $del->execute([':id' => $userId]);
    }
}
