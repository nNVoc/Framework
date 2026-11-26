<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Poletti\Framework\Locking\Exceptions\LockTimeoutException;
use Poletti\Framework\Locking\LockManager;
use Poletti\Framework\Locking\Store\MySqlLockStore;
use Poletti\Framework\RateLimiting\Store\MySqlRateLimitStore;

/**
 * Run with RATE_LIMIT_TEST_DSN, RATE_LIMIT_TEST_USER and RATE_LIMIT_TEST_PASSWORD.
 * Uses a connection-local temporary table; existing application data is untouched.
 */
final class MySqlRateLimitingTest extends TestCase
{
    private PDO $pdo;
    private MySqlRateLimitStore $store;

    protected function setUp(): void
    {
        if (!getenv('RATE_LIMIT_TEST_DSN')) {
            self::markTestSkipped('Set RATE_LIMIT_TEST_DSN to run against MySQL.');
        }
        $this->pdo = $this->connect();
        $migration = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/create_rate_limits_table.sql');
        $this->pdo->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $migration));
        $this->store = new MySqlRateLimitStore($this->pdo, new LockManager(new MySqlLockStore($this->pdo)));
    }

    private function connect(): PDO
    {
        return new PDO(getenv('RATE_LIMIT_TEST_DSN'), getenv('RATE_LIMIT_TEST_USER') ?: '', getenv('RATE_LIMIT_TEST_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public function testQuotaBlockIsolationAndReset(): void
    {
        self::assertSame(2, $this->store->remaining('login:ip:192.0.2.1', 2, 60));
        self::assertSame(1, $this->store->attempt('login:ip:192.0.2.1', 2, 60)->remaining);
        self::assertTrue($this->store->attempt('login:ip:192.0.2.1', 2, 60)->allowed);
        $blocked = $this->store->attempt('login:ip:192.0.2.1', 2, 60);
        self::assertFalse($blocked->allowed);
        self::assertSame(0, $blocked->remaining);
        self::assertGreaterThanOrEqual(1, $blocked->retryAfter);
        self::assertLessThanOrEqual(60, $blocked->retryAfter);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn());
        self::assertTrue($this->store->attempt('login:ip:192.0.2.2', 2, 60)->allowed);
        self::assertTrue($this->store->attempt('api:ip:192.0.2.1', 2, 60)->allowed);
        $this->store->reset('login:ip:192.0.2.1');
        self::assertSame(2, $this->store->remaining('login:ip:192.0.2.1', 2, 60));
        self::assertSame(1, $this->store->remaining('login:ip:192.0.2.2', 2, 60));
    }

    public function testExpiredAttemptsAreRemovedAndHitConsumesQuota(): void
    {
        $this->store->hit('key', 60);
        self::assertSame(0, $this->store->remaining('key', 1, 60));
        self::assertFalse($this->store->attempt('key', 1, 60)->allowed);
        $this->pdo->exec('UPDATE rate_limits SET attempted_at = NOW(6) - INTERVAL 61 SECOND');
        self::assertSame(1, $this->store->remaining('key', 1, 60));
        self::assertTrue($this->store->attempt('key', 1, 60)->allowed);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn());
    }

    public function testLockContentionAndIdempotentRelease(): void
    {
        $key = 'test:' . bin2hex(random_bytes(16));
        $first = new MySqlLockStore($this->pdo);
        $second = new MySqlLockStore($this->connect());
        $lock = $first->acquire($key, 0);
        try {
            try {
                $second->acquire($key, 0);
                self::fail('Second connection acquired an occupied lock.');
            } catch (LockTimeoutException) {
                self::assertTrue(true);
            }
        } finally {
            $lock->release();
            $lock->release();
        }
        $second->acquire($key, 0)->release();
    }
}
