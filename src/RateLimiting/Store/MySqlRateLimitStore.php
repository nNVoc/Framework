<?php

declare(strict_types=1);

namespace Poletti\Framework\RateLimiting\Store;

use PDO;
use InvalidArgumentException;
use LogicException;
use Poletti\Framework\Locking\LockManager;
use RuntimeException;
use Poletti\Framework\RateLimiting\RateLimitResult;
use Poletti\Framework\RateLimiting\RateLimitStoreInterface;

final class MySqlRateLimitStore implements RateLimitStoreInterface
{
    public function __construct(
        private PDO $pdo,
        private LockManager $lock
    ) {}

    public function attempt(
        string $key,
        int $maxAttempts,
        int $decaySeconds
    ): RateLimitResult {
        $this->validate($maxAttempts, $decaySeconds);

        return $this->lock->synchronized(
            "rl:{$key}",
            function () use ($key, $maxAttempts, $decaySeconds): RateLimitResult {
                $hash = $this->scopeHash($key);

                $this->clearExpired($hash, $decaySeconds);

                $stmt = $this->pdo->prepare(<<<'SQL'
                    SELECT
                        COUNT(*) AS attempts,
                        GREATEST(
                            1,
                            CEIL(
                                TIMESTAMPDIFF(
                                    MICROSECOND,
                                    NOW(6),
                                    DATE_ADD(
                                        MIN(attempted_at),
                                        INTERVAL ? SECOND
                                    )
                                ) / 1000000
                            )
                        ) AS retry_after
                    FROM rate_limits
                    WHERE scope_hash = ?
                      AND attempted_at > NOW(6) - INTERVAL ? SECOND
                SQL);

                $stmt->execute([
                    $decaySeconds,
                    $hash,
                    $decaySeconds
                ]);

                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                $attempts = (int) $row['attempts'];

                if ($attempts >= $maxAttempts) {
                    return new RateLimitResult(
                        allowed: false,
                        remaining: 0,
                        retryAfter: (int) $row['retry_after']
                    );
                }

                $this->insertAttempt($hash);

                return new RateLimitResult(
                    allowed: true,
                    remaining: $maxAttempts - $attempts - 1,
                    retryAfter: 0
                );
            }
        );
    }

    public function hit(
        string $key,
        int $decaySeconds
    ): void {
        $this->validate(1, $decaySeconds);

        $this->lock->synchronized(
            "rl:{$key}",
            function () use ($key, $decaySeconds): void {
                $hash = $this->scopeHash($key);

                $this->clearExpired($hash, $decaySeconds);
                $this->insertAttempt($hash);
            }
        );
    }

    public function remaining(
        string $key,
        int $maxAttempts,
        int $decaySeconds
    ): int {
        $this->validate($maxAttempts, $decaySeconds);

        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM rate_limits
            WHERE scope_hash = ?
            AND attempted_at > NOW(6) - INTERVAL ? SECOND
        SQL);

        $stmt->execute([
            $this->scopeHash($key),
            $decaySeconds
        ]);

        $attempts = (int) $stmt->fetchColumn();

        return max(0, $maxAttempts - $attempts);
    }

    public function reset(string $key): void
    {
        $this->lock->synchronized(
            "rl:{$key}",
            function () use ($key): void {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM rate_limits WHERE scope_hash = ?'
                );

                $stmt->execute([
                    $this->scopeHash($key)
                ]);
            }
        );
    }

    private function insertAttempt(string $hash): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (scope_hash) VALUES (?)'
        );

        $stmt->execute([$hash]);
    }

    private function clearExpired(
        string $hash,
        int $decaySeconds
    ): void {
        $stmt = $this->pdo->prepare(<<<'SQL'
            DELETE FROM rate_limits
            WHERE scope_hash = ?
              AND attempted_at <= NOW(6) - INTERVAL ? SECOND
        SQL);

        $stmt->execute([$hash, $decaySeconds]);
    }

    private function scopeHash(string $key): string
    {
        return hash('sha256', $key);
    }

    private function validate(
        int $maxAttempts,
        int $decaySeconds
    ): void {
        if ($maxAttempts < 1 || $decaySeconds < 1) {
            throw new InvalidArgumentException(
                'Rate limit parameters must be positive.'
            );
        }
    }
}
