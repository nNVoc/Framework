<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Poletti\Framework\Http\Middleware\SecurityHeadersMiddleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use RuntimeException;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    public function testAddsDefaultHeadersAndPreservesResponse(): void
    {
        $request = new Request([]);
        $response = new Response('Created', 201, ['Content-Type' => 'text/plain']);

        $result = (new SecurityHeadersMiddleware())->handle(
            $request,
            function (Request $received) use ($request, $response): Response {
                self::assertSame($request, $received);
                return $response;
            }
        );

        self::assertSame($response, $result);
        self::assertSame(201, $result->status());
        self::assertSame([
            'Content-Type' => 'text/plain',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-XSS-Protection' => '0',
        ], $result->headers());
    }

    public function testConfigurationOverridesDefaultsAndAddsCustomHeaders(): void
    {
        $middleware = new SecurityHeadersMiddleware([
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'self'",
        ]);

        $response = $middleware->handle(new Request([]), static fn () => new Response());

        self::assertSame('SAMEORIGIN', $response->headers()['X-Frame-Options']);
        self::assertSame("default-src 'self'", $response->headers()['Content-Security-Policy']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
    }

    public function testEnforcesHeadersOverDownstreamValuesRegardlessOfCase(): void
    {
        $response = new Response('', 403, ['x-frame-options' => 'SAMEORIGIN', 'X-App' => 'test']);

        (new SecurityHeadersMiddleware())->handle(new Request([]), static fn () => $response);

        self::assertSame('DENY', $response->headers()['x-frame-options']);
        self::assertArrayNotHasKey('X-Frame-Options', $response->headers());
        self::assertSame('test', $response->headers()['X-App']);
        self::assertSame(403, $response->status());
    }

    public function testUnhandledDownstreamExceptionPropagates(): void
    {
        $failure = new RuntimeException('Unhandled failure');

        $this->expectExceptionObject($failure);
        (new SecurityHeadersMiddleware())->handle(new Request([]), static fn (): Response => throw $failure);
    }
}
