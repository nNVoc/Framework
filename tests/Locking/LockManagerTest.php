<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Locking;

use PHPUnit\Framework\TestCase;
use Poletti\Framework\Locking\LockInterface;
use Poletti\Framework\Locking\LockManager;
use Poletti\Framework\Locking\Store\LockStoreInterface;
use RuntimeException;

final class LockManagerTest extends TestCase
{
    public function testSynchronizedReturnsResultAndReleasesLock(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())->method('release');
        $store = $this->createMock(LockStoreInterface::class);
        $store->expects(self::once())->method('acquire')->with('key', 2)->willReturn($lock);
        self::assertSame(42, (new LockManager($store))->synchronized('key', fn () => 42, 2));
    }

    public function testSynchronizedReleasesLockWhenCallbackThrows(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())->method('release');
        $store = $this->createStub(LockStoreInterface::class);
        $store->method('acquire')->willReturn($lock);
        $exception = new RuntimeException('callback failed');
        try {
            (new LockManager($store))->synchronized('key', fn () => throw $exception);
            self::fail('Expected exception.');
        } catch (RuntimeException $received) {
            self::assertSame($exception, $received);
        }
    }

    public function testAcquireFailureDoesNotRunCallback(): void
    {
        $store = $this->createStub(LockStoreInterface::class);
        $store->method('acquire')->willThrowException(new RuntimeException('unavailable'));
        $this->expectExceptionMessage('unavailable');
        (new LockManager($store))->synchronized('key', function (): void {
            self::fail('Callback ran without lock.');
        });
    }
}
