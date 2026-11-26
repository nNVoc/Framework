<?php

namespace Poletti\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Poletti\Framework\Http\Middleware\CorsMiddleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;

final class CorsMiddlewareTest extends TestCase
{
    private CorsMiddleware $cors;

    protected function setUp(): void
    {
        $this->cors = new CorsMiddleware(
            allowedOrigins: ['https://app.exemplo.com'],
            allowedMethods: ['GET', 'POST', 'PUT'],
            allowedHeaders: ['Content-Type', 'Authorization'],
        );
    }

    private function request(
        string $method,
        array $headers = []
    ): Request {
        return new class($method, $headers) extends Request {
            public function __construct(
                private string $httpMethod,
                private array $httpHeaders
            ) {}

            public function method(): string
            {
                return $this->httpMethod;
            }

            public function header(string $name): ?string
            {
                foreach ($this->httpHeaders as $key => $value) {
                    if (strcasecmp($key, $name) === 0) {
                        return $value;
                    }
                }

                return null;
            }
        };
    }

    public function testAllowsValidPreflight(): void
    {
        $request = $this->request('OPTIONS', [
            'Origin' => 'https://app.exemplo.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type',
        ]);

        $next = function (): Response {
            $this->fail('Preflight não deve executar o próximo middleware.');
        };

        $response = $this->cors->handle($request, $next);

        $this->assertSame(204, $response->status());

        $this->assertSame(
            'https://app.exemplo.com',
            $response->headers()['Access-Control-Allow-Origin']
        );

        $this->assertSame(
            'POST',
            $response->headers()['Access-Control-Allow-Methods']
        );
    }

    public function testRejectsForbiddenOrigin(): void
    {
        $request = $this->request('OPTIONS', [
            'Origin' => 'https://malicioso.com',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $response = $this->cors->handle(
            $request,
            fn (): Response => new Response('OK')
        );

        $this->assertSame(403, $response->status());
        $this->assertArrayNotHasKey(
            'Access-Control-Allow-Origin',
            $response->headers()
        );
    }

    public function testRejectsForbiddenMethod(): void
    {
        $request = $this->request('OPTIONS', [
            'Origin' => 'https://app.exemplo.com',
            'Access-Control-Request-Method' => 'DELETE',
        ]);

        $response = $this->cors->handle(
            $request,
            fn (): Response => new Response('OK')
        );

        $this->assertSame(403, $response->status());
    }

    public function testRejectsForbiddenHeader(): void
    {
        $request = $this->request('OPTIONS', [
            'Origin' => 'https://app.exemplo.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'X-Unknown',
        ]);

        $response = $this->cors->handle(
            $request,
            fn (): Response => new Response('OK')
        );

        $this->assertSame(403, $response->status());
    }

    public function testAllowsNormalRequest(): void
    {
        $request = $this->request('GET', [
            'Origin' => 'https://app.exemplo.com',
        ]);

        $nextCalled = false;

        $next = function (Request $request) use (&$nextCalled): Response {
            $nextCalled = true;
            return new Response('OK', 200);
        };

        $response = $this->cors->handle($request, $next);

        $this->assertTrue($nextCalled);
        $this->assertSame(200, $response->status());

        $this->assertSame(
            'https://app.exemplo.com',
            $response->headers()['Access-Control-Allow-Origin']
        );
    }

    public function testRejectsLowercaseConfiguredMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CorsMiddleware(
            allowedMethods: ['get', 'post']
        );
    }

    #[DataProvider('normalRequests')]
    public function testNormalRequestsPreserveResponseAndApplyOriginPolicy(?string $origin, bool $allowed): void
    {
        $request = new Request([
            'REQUEST_METHOD' => 'GET',
            ...($origin === null ? [] : ['HTTP_ORIGIN' => $origin]),
        ]);
        $response = new Response('Created', 201, ['Vary' => 'Accept-Encoding', 'X-App' => 'test']);

        $result = $this->cors->handle($request, function (Request $received) use ($request, $response): Response {
            self::assertSame($request, $received);
            return $response;
        });

        self::assertSame($response, $result);
        self::assertSame(201, $result->status());
        self::assertSame('test', $result->headers()['X-App']);
        self::assertSame($origin === null ? 'Accept-Encoding' : 'Accept-Encoding, Origin', $result->headers()['Vary']);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $result->headers());
        if ($allowed) {
            self::assertSame($origin, $result->headers()['Access-Control-Allow-Origin']);
        } else {
            self::assertArrayNotHasKey('Access-Control-Allow-Origin', $result->headers());
        }
    }

    public static function normalRequests(): iterable
    {
        yield 'allowed origin' => ['https://app.exemplo.com', true];
        yield 'forbidden origin still reaches application' => ['https://other.example.com', false];
        yield 'no origin' => [null, false];
        yield 'similar domain is not allowed' => ['https://app.exemplo.com.evil.test', false];
    }

    #[DataProvider('preflightRequests')]
    public function testPreflightShortCircuitsWithoutExecutingNext(array $headers, int $status, ?string $allowedHeaders): void
    {
        $response = $this->cors->handle(new Request([
            'REQUEST_METHOD' => 'OPTIONS',
            ...$headers,
        ]), function (): Response {
            self::fail('Preflight must not execute downstream middleware.');
        });

        self::assertSame($status, $response->status());
        self::assertSame('Origin', $response->headers()['Vary']);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers());
        if ($status === 204) {
            self::assertSame('https://app.exemplo.com', $response->headers()['Access-Control-Allow-Origin']);
            self::assertSame($allowedHeaders, $response->headers()['Access-Control-Allow-Headers']);
        } else {
            self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers());
        }
    }

    public static function preflightRequests(): iterable
    {
        $headers = [
            'HTTP_ORIGIN' => 'https://app.exemplo.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ];
        yield 'no requested headers' => [$headers, 204, ''];
        yield 'case and whitespace in headers' => [
            $headers + ['HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => ' Content-TYPE , AUTHORIZATION '],
            204, 'content-type, authorization',
        ];
        yield 'forbidden origin' => [array_replace($headers, ['HTTP_ORIGIN' => 'https://other.example.com']), 403, null];
        yield 'forbidden method' => [array_replace($headers, ['HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'DELETE']), 403, null];
        yield 'forbidden header among allowed headers' => [
            $headers + ['HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, X-Unknown'], 403, null,
        ];
    }

    #[DataProvider('credentialRequests')]
    public function testCredentialsAreOnlyReturnedForAllowedOrigin(string $method, string $origin, bool $allowed): void
    {
        $cors = new CorsMiddleware(allowedOrigins: ['https://app.exemplo.com'], allowCredentials: true);
        $response = $cors->handle(new Request([
            'REQUEST_METHOD' => $method,
            'HTTP_ORIGIN' => $origin,
            ...($method === 'OPTIONS' ? ['HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'] : []),
        ]), static fn () => new Response());

        if ($allowed) {
            self::assertSame('true', $response->headers()['Access-Control-Allow-Credentials']);
            self::assertSame($origin, $response->headers()['Access-Control-Allow-Origin']);
        } else {
            self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $response->headers());
            self::assertArrayNotHasKey('Access-Control-Allow-Origin', $response->headers());
        }
    }

    public static function credentialRequests(): iterable
    {
        foreach (['GET', 'OPTIONS'] as $method) {
            yield $method . ' allowed' => [$method, 'https://app.exemplo.com', true];
            yield $method . ' forbidden' => [$method, 'https://other.example.com', false];
        }
    }

    #[DataProvider('incompletePreflights')]
    public function testOptionsWithoutCompletePreflightHeadersReachesNext(array $headers): void
    {
        $request = new Request(['REQUEST_METHOD' => 'OPTIONS', ...$headers]);
        $response = new Response('Application OPTIONS', 200);

        self::assertSame($response, $this->cors->handle($request, function (Request $received) use ($request, $response): Response {
            self::assertSame($request, $received);
            return $response;
        }));
    }

    public static function incompletePreflights(): iterable
    {
        yield 'no headers' => [[]];
        yield 'origin only' => [['HTTP_ORIGIN' => 'https://app.exemplo.com']];
        yield 'requested method only' => [['HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']];
    }
}
