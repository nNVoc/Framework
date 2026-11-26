<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Poletti\Framework\Container\Container;
use Poletti\Framework\Exceptions\ClientIpUnavailableException;
use Poletti\Framework\Exceptions\ExceptionHandler;
use Poletti\Framework\Http\Middleware\CorsMiddleware;
use Poletti\Framework\Http\Middleware\ExceptionMiddleware;
use Poletti\Framework\Http\Middleware\RateLimitMiddleware;
use Poletti\Framework\Locking\Exceptions\LockTimeoutException;
use Poletti\Framework\RateLimiting\RateLimiter;
use Poletti\Framework\RateLimiting\RateLimitResult;
use Poletti\Framework\RateLimiting\RateLimitStoreInterface;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use Poletti\Framework\Routing\Router;

final class RateLimitMiddlewareTest extends TestCase
{
    public function testAllowedRequestReachesHandlerAndBlockedRequestDoesNot(): void
    {
        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->expects(self::exactly(2))->method('attempt')
            ->with('login:ip:192.0.2.1', 1, 60)
            ->willReturnOnConsecutiveCalls(new RateLimitResult(true, 0, 0), new RateLimitResult(false, 0, 42));
        $middleware = new RateLimitMiddleware(new RateLimiter($store), 1, 60, 'login');
        $request = new Request(['REMOTE_ADDR' => '192.0.2.1']);
        $response = new Response('OK', 201);
        self::assertSame($response, $middleware->handle($request, function (Request $received) use ($request, $response): Response {
            self::assertSame($request, $received);
            return $response;
        }));
        $blocked = $middleware->handle($request, function (): Response {
            self::fail('Blocked request reached handler.');
        });
        self::assertSame(429, $blocked->status());
        self::assertSame('42', $blocked->headers()['Retry-After']);
    }

    public function testMissingIpDoesNotConsumeQuotaOrReachHandler(): void
    {
        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->expects(self::never())->method('attempt');
        $middleware = new RateLimitMiddleware(new RateLimiter($store), 1, 60, 'login');
        $this->expectException(ClientIpUnavailableException::class);
        $middleware->handle(new Request([]), function (): Response {
            self::fail('Request without IP reached handler.');
        });
    }

    #[DataProvider('invalidIps')]
    public function testInvalidIpDoesNotConsumeQuotaOrReachHandler(mixed $ip): void
    {
        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->expects(self::never())->method('attempt');

        $this->expectException(ClientIpUnavailableException::class);
        (new RateLimitMiddleware(new RateLimiter($store), 1, 60, 'api'))->handle(
            new Request(['REMOTE_ADDR' => $ip, 'HTTP_X_FORWARDED_FOR' => '192.0.2.1']),
            function (): Response {
                self::fail('An invalid client IP must stop the chain.');
            }
        );
    }

    public static function invalidIps(): iterable
    {
        yield 'empty' => [''];
        yield 'malformed' => ['not-an-ip'];
        yield 'list of IPs' => ['192.0.2.1, 192.0.2.2'];
        yield 'non-string' => [123];
    }

    public function testEquivalentIpv6AddressesShareQuotaKey(): void
    {
        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->expects(self::exactly(2))->method('attempt')
            ->with('api:ip:2001:db8::1', 10, 60)
            ->willReturn(new RateLimitResult(true, 9, 0));
        $middleware = new RateLimitMiddleware(new RateLimiter($store), 10, 60, 'api');
        $response = new Response();

        foreach (['2001:db8::1', '2001:0DB8:0000:0000:0000:0000:0000:0001'] as $ip) {
            self::assertSame($response, $middleware->handle(new Request(['REMOTE_ADDR' => $ip]), static fn () => $response));
        }
    }

    public function testKeysSeparateIpsAndScopesAndIgnoreForwardedHeader(): void
    {
        $keys = [];
        $store = $this->createStub(RateLimitStoreInterface::class);
        $store->method('attempt')->willReturnCallback(function (string $key) use (&$keys): RateLimitResult {
            $keys[] = $key;
            return new RateLimitResult(true, 0, 0);
        });
        $limiter = new RateLimiter($store);
        foreach ([['login', '192.0.2.1'], ['login', '192.0.2.2'], ['api', '192.0.2.1']] as [$scope, $ip]) {
            (new RateLimitMiddleware($limiter, 1, 60, $scope))->handle(
                new Request(['REMOTE_ADDR' => $ip, 'HTTP_X_FORWARDED_FOR' => '203.0.113.1']),
                fn () => new Response()
            );
        }
        self::assertSame(['login:ip:192.0.2.1', 'login:ip:192.0.2.2', 'api:ip:192.0.2.1'], $keys);
    }

    public function testRouterPreservesCorsOn429AndPreflightDoesNotConsumeQuota(): void
    {
        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->expects(self::once())->method('attempt')->willReturn(new RateLimitResult(false, 0, 10));
        $router = new Router(new Container());
        $router->middleware(new CorsMiddleware(allowedOrigins: ['https://app.example.com']));
        $router->middleware(new ExceptionMiddleware(new ExceptionHandler()));
        $router->middleware(new RateLimitMiddleware(new RateLimiter($store), 1, 60, 'api'));
        $router->get('/api', function (): Response {
            self::fail('Blocked route executed.');
        });
        $server = ['REMOTE_ADDR' => '192.0.2.1', 'REQUEST_URI' => '/api', 'HTTP_ORIGIN' => 'https://app.example.com'];
        $preflight = $router->dispatch(new Request($server + [
            'REQUEST_METHOD' => 'OPTIONS', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]));
        self::assertSame(204, $preflight->status());
        $response = $router->dispatch(new Request($server + ['REQUEST_METHOD' => 'GET']));
        self::assertSame(429, $response->status());
        self::assertSame('10', $response->headers()['Retry-After']);
        self::assertSame('https://app.example.com', $response->headers()['Access-Control-Allow-Origin']);
    }

    public function testLockTimeoutReturns503WithoutRunningHandler(): void
    {
        $store = $this->createStub(RateLimitStoreInterface::class);
        $store->method('attempt')->willThrowException(new LockTimeoutException('busy'));
        $rateLimit = new RateLimitMiddleware(new RateLimiter($store), 1, 60, 'api');
        // Avoid writing the expected 503 trace to the test runner output.
        $log = ini_get('error_log');
        ini_set('error_log', '/dev/null');
        try {
            $response = (new ExceptionMiddleware(new ExceptionHandler()))->handle(
                new Request(['REMOTE_ADDR' => '192.0.2.1']),
                fn (Request $request) => $rateLimit->handle($request, function (): Response {
                    self::fail('Lock failure reached handler.');
                })
            );
            self::assertSame(503, $response->status());
        } finally {
            ini_set('error_log', $log);
        }
    }
}
