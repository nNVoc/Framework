<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Http\Middleware;

use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poletti\Framework\Container\Container;
use Poletti\Framework\Exceptions\ExceptionHandler;
use Poletti\Framework\Exceptions\HttpException;
use Poletti\Framework\Http\Middleware\CorsMiddleware;
use Poletti\Framework\Http\Middleware\CsrfMiddleware;
use Poletti\Framework\Http\Middleware\ExceptionMiddleware;
use Poletti\Framework\Http\Middleware\RateLimitMiddleware;
use Poletti\Framework\Http\Middleware\SecurityHeadersMiddleware;
use Poletti\Framework\Http\Middleware\SessionMiddleware;
use Poletti\Framework\Locking\Exceptions\LockTimeoutException;
use Poletti\Framework\RateLimiting\RateLimiter;
use Poletti\Framework\RateLimiting\RateLimitResult;
use Poletti\Framework\RateLimiting\RateLimitStoreInterface;
use Poletti\Framework\Routing\Middleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use Poletti\Framework\Routing\Router;
use Poletti\Framework\Security\CsrfTokenManager;
use Poletti\Framework\Session\SessionInterface;
use Poletti\Framework\Tests\Support\ResponseCapture;
use RuntimeException;
use Throwable;

final class MiddlewarePipelineTest extends TestCase
{
    #[DataProvider('requests')]
    public function testFullChainOrderAndShortCircuits(
        string $method,
        string $path,
        ?string $token,
        bool $allowed,
        bool $controllerThrows,
        int $status,
        array $expectedEvents,
        string $expectedBody
    ): void {
        $events = [];
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('start')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'session';
        });
        $session->expects(in_array('csrf', $expectedEvents, true) ? self::once() : self::never())
            ->method('get')->with('_csrf')->willReturnCallback(static function () use (&$events): string {
                $events[] = 'csrf';
                return str_repeat('a', 64);
            });
        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->expects(in_array('rate', $expectedEvents, true) ? self::once() : self::never())
            ->method('attempt')->with('api:ip:192.0.2.1', 10, 60)
            ->willReturnCallback(static function () use (&$events, $allowed): RateLimitResult {
                $events[] = 'rate';
                return new RateLimitResult($allowed, 0, 30);
            });
        $router = $this->router($session, $store);
        $controller = static function () use (&$events, $controllerThrows): Response {
            $events[] = 'controller';
            if ($controllerThrows) {
                throw new HttpException(403, 'Action forbidden');
            }
            return new Response('Created', 201);
        };
        $router->get('/resource', $controller);
        $router->post('/resource', $controller);

        $response = $router->dispatch($this->request($method, $path, $token));

        self::assertSame($expectedEvents, $events);
        self::assertSame($status, $response->status());
        $this->assertResponseHeaders($response);
        $this->assertBody($expectedBody, $response);
        if ($status === 429) {
            self::assertSame('30', $response->headers()['Retry-After']);
        } elseif ($status >= 400) {
            self::assertSame('application/json', $response->headers()['Content-Type']);
        }
    }

    public static function requests(): iterable
    {
        $token = str_repeat('a', 64);
        yield 'GET skips CSRF validation' => ['GET', '/resource', null, true, false, 201,
            ['session', 'rate', 'controller'], 'Created'];
        yield 'POST validates CSRF before quota' => ['POST', '/resource', $token, true, false, 201,
            ['session', 'csrf', 'rate', 'controller'], 'Created'];
        yield 'missing CSRF does not consume quota' => ['POST', '/resource', null, true, false, 403,
            ['session'], '{"message":"Invalid CSRF token"}'];
        yield 'invalid CSRF does not consume quota' => ['POST', '/resource', str_repeat('b', 64), true, false, 403,
            ['session', 'csrf'], '{"message":"Invalid CSRF token"}'];
        yield 'quota exhausted stops controller' => ['POST', '/resource', $token, false, false, 429,
            ['session', 'csrf', 'rate'], 'Too Many Requests'];
        yield 'controller exception is handled' => ['POST', '/resource', $token, true, true, 403,
            ['session', 'csrf', 'rate', 'controller'], '{"message":"Action forbidden"}'];
        yield 'unknown route is handled' => ['GET', '/missing', null, true, false, 404,
            ['session', 'rate'], '{"message":"Not Found"}'];
        yield 'unsupported route method is handled' => ['PUT', '/resource', $token, true, false, 405,
            ['session', 'csrf', 'rate'], '{"message":"Method Not Allowed"}'];
    }

    #[DataProvider('preflightOrigins')]
    public function testPreflightStopsBeforeSessionCsrfAndRateLimiter(string $origin, int $status): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::never())->method('start');
        $session->expects(self::never())->method('get');
        $store = $this->createMock(RateLimitStoreInterface::class);
        $store->expects(self::never())->method('attempt');
        $router = $this->router($session, $store);
        $router->options('/resource', function (): Response {
            self::fail('Preflight must not execute the controller.');
        });

        $response = $router->dispatch(new Request([
            'REQUEST_METHOD' => 'OPTIONS',
            'REQUEST_URI' => '/resource',
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'X-CSRF-Token',
        ]));

        self::assertSame($status, $response->status());
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertSame('DENY', $response->headers()['X-Frame-Options']);
        self::assertSame('Origin', $response->headers()['Vary']);
        if ($status === 204) {
            self::assertSame($origin, $response->headers()['Access-Control-Allow-Origin']);
        } else {
            self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers());
        }
        $this->assertBody('', $response);
    }

    public static function preflightOrigins(): iterable
    {
        yield 'allowed' => ['https://app.example.com', 204];
        yield 'forbidden' => ['https://other.example.com', 403];
    }

    #[DataProvider('failures')]
    public function testDownstreamFailuresBecomeResponsesWithCorsAndSecurityHeaders(
        string $source,
        Throwable $failure,
        int $status,
        string $message
    ): void {
        $session = $this->createMock(SessionInterface::class);
        $start = $session->expects(self::once())->method('start');
        if ($source === 'session') {
            $start->willThrowException($failure);
        }
        $session->expects(self::never())->method('get');
        $store = $this->createMock(RateLimitStoreInterface::class);
        $attempt = $store->expects($source === 'session' ? self::never() : self::once())->method('attempt');
        if ($source === 'limiter') {
            $attempt->willThrowException($failure);
        } else {
            $attempt->willReturn(new RateLimitResult(true, 9, 0));
        }
        $router = $this->router($session, $store);
        $router->get('/resource', function () use ($source, $failure): Response {
            self::assertSame('controller', $source, 'Infrastructure failure must stop before the controller.');
            throw $failure;
        });

        // The real handler logs expected 5xx failures; keep those traces out of test output.
        $log = ini_get('error_log');
        ini_set('error_log', '/dev/null');
        try {
            $response = $router->dispatch($this->request('GET', '/resource'));
        } finally {
            ini_set('error_log', $log);
        }

        self::assertSame($status, $response->status());
        self::assertSame('application/json', $response->headers()['Content-Type']);
        $this->assertResponseHeaders($response);
        $this->assertBody(json_encode(['message' => $message], JSON_THROW_ON_ERROR), $response);
    }

    public static function failures(): iterable
    {
        yield 'session startup' => ['session', new RuntimeException('Sensitive session details'), 500, 'Internal Server Error'];
        yield 'quota storage' => ['limiter', new RuntimeException('Sensitive storage details'), 500, 'Internal Server Error'];
        yield 'lock timeout' => ['limiter', new LockTimeoutException('Lock busy'), 503, 'Service Unavailable'];
        yield 'controller PHP error' => ['controller', new Error('Sensitive controller details'), 500, 'Internal Server Error'];
    }

    #[DataProvider('outerFailures')]
    public function testExceptionMiddlewareDoesNotCatchFailuresInEarlierMiddleware(bool $afterNext): void
    {
        $failure = new RuntimeException('Outer middleware failed');
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects(self::never())->method('handle');
        $router = new Router(new Container());
        $router->middleware(new class($failure, $afterNext) implements Middleware {
            public function __construct(private Throwable $failure, private bool $afterNext) {}

            public function handle(Request $request, callable $next): Response
            {
                if ($this->afterNext) {
                    $next($request);
                }
                throw $this->failure;
            }
        });
        $router->middleware(new ExceptionMiddleware($handler));
        $router->get('/resource', static fn () => new Response());

        $this->expectExceptionObject($failure);
        $router->dispatch($this->request('GET', '/resource'));
    }

    public static function outerFailures(): iterable
    {
        yield 'before next' => [false];
        yield 'after next' => [true];
    }

    private function router(SessionInterface $session, RateLimitStoreInterface $store): Router
    {
        return (new Router(new Container()))
            ->middleware(new SecurityHeadersMiddleware())
            ->middleware(new CorsMiddleware(allowedOrigins: ['https://app.example.com']))
            ->middleware(new ExceptionMiddleware(new ExceptionHandler()))
            ->middleware(new SessionMiddleware($session))
            ->middleware(new CsrfMiddleware(new CsrfTokenManager($session)))
            ->middleware(new RateLimitMiddleware(new RateLimiter($store), 10, 60, 'api'));
    }

    private function request(string $method, string $path, ?string $token = null): Request
    {
        return new Request([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'REMOTE_ADDR' => '192.0.2.1',
            'HTTP_ORIGIN' => 'https://app.example.com',
            ...($token === null ? [] : ['HTTP_X_CSRF_TOKEN' => $token]),
        ]);
    }

    private function assertResponseHeaders(Response $response): void
    {
        self::assertSame('https://app.example.com', $response->headers()['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $response->headers()['Vary']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertSame('DENY', $response->headers()['X-Frame-Options']);
        self::assertSame('strict-origin-when-cross-origin', $response->headers()['Referrer-Policy']);
        self::assertSame('0', $response->headers()['X-XSS-Protection']);
    }

    private function assertBody(string $expected, Response $response): void
    {
        $originalStatus = http_response_code();
        try {
            [$body] = ResponseCapture::capture($response);
            self::assertSame($expected, $body);
        } finally {
            http_response_code($originalStatus === false ? 200 : $originalStatus);
        }
    }
}
