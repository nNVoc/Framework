<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\RateLimiting;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poletti\Framework\RateLimiting\RateLimiter;
use Poletti\Framework\RateLimiting\RateLimitResult;
use Poletti\Framework\RateLimiting\RateLimitStoreInterface;

final class RateLimiterTest extends TestCase
{
    public function testDelegatesToStore(): void
    {
        $store = $this->createMock(RateLimitStoreInterface::class);
        $result = new RateLimitResult(true, 2, 0);
        $store->expects(self::once())->method('attempt')->with('key', 3, 60)->willReturn($result);
        $store->expects(self::once())->method('remaining')->with('key', 3, 60)->willReturn(2);
        $store->expects(self::once())->method('reset')->with('key');
        $limiter = new RateLimiter($store);
        self::assertSame($result, $limiter->attempt('key', 3, 60));
        self::assertSame(2, $limiter->remaining('key', 3, 60));
        $limiter->reset('key');
    }

    #[DataProvider('invalidLimits')]
    public function testRejectsInvalidLimitsBeforeAccessingStore(string $method, int $max, int $decay): void
    {
        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->expects(self::never())->method($method);
        $this->expectException(InvalidArgumentException::class);
        (new RateLimiter($store))->$method('key', $max, $decay);
    }

    public static function invalidLimits(): iterable
    {
        foreach (['attempt', 'remaining'] as $method) {
            foreach ([[0, 60], [-1, 60], [1, 0], [1, -1]] as [$max, $decay]) {
                yield [$method, $max, $decay];
            }
        }
    }
}
