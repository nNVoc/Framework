<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Poletti\Framework\Container\Container;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use Poletti\Framework\Routing\Route;
use Poletti\Framework\Tests\Support\FixtureController;
use Poletti\Framework\Tests\Support\ResponseCapture;
use Poletti\Framework\Tests\Support\TraceMiddleware;
use Poletti\Framework\Tests\Support;

final class RouteTest extends TestCase
{
    public function testMatchReturnsAnEmptyArrayForAStaticPath(): void
    {
        $route = new Route('GET', '/about', Support\fixture_handler(...), new Container());

        self::assertSame([], $route->match('GET', '/about'));
    }

    public function testMatchExtractsOnePathParameter(): void
    {
        $route = new Route('GET', '/users/{id}', Support\fixture_handler(...), new Container());

        self::assertSame(['id' => '42'], $route->match('GET', '/users/42'));
    }

    public function testMatchExtractsMultiplePathParameters(): void
    {
        $route = new Route('GET', '/teams/{team}/users/{user}', Support\fixture_handler(...), new Container());

        self::assertSame(
            ['team' => '7', 'user' => '42'],
            $route->match('GET', '/teams/7/users/42')
        );
    }

    public function testMatchReturnsNullWhenThePathDoesNotMatch(): void
    {
        $route = new Route('GET', '/users/{id}', Support\fixture_handler(...), new Container());

        self::assertNull($route->match('GET', '/posts/42'));
    }

    public function testMatchReturnsFalseWhenThePathMatchesButMethodDoesNot(): void
    {
        $route = new Route('GET', '/users/{id}', Support\fixture_handler(...), new Container());

        self::assertFalse($route->match('POST', '/users/42'));
    }

    public function testRunInvokesAFunctionHandler(): void
    {
        $route = new Route('GET', '/users/{id}', Support\fixture_handler(...), new Container());
        $response = $route->run($this->request('GET', '/users/42'), ['id' => '42']);

        [$body] = ResponseCapture::capture($response);

        self::assertSame('function:GET:42', $body);
    }

    public function testRunResolvesAControllerClassAndUsesItsIndexMethod(): void
    {
        $route = new Route('GET', '/users/{id}', FixtureController::class, new Container());
        $response = $route->run($this->request('GET', '/users/42'), ['id' => '42']);

        [$body] = ResponseCapture::capture($response);

        self::assertSame('controller:42', $body);
    }

    public function testRunResolvesAControllerArrayAndUsesTheSpecifiedMethod(): void
    {
        $route = new Route(
            'GET',
            '/users/{id}',
            [FixtureController::class, 'show'],
            new Container()
        );
        $response = $route->run($this->request('GET', '/users/42'), ['id' => '42']);

        [$body] = ResponseCapture::capture($response);

        self::assertSame('show:42', $body);
    }

    public function testGlobalMiddlewareRunsBeforeRouteMiddleware(): void
    {
        $trace = (object) ['events' => []];
        $global = new TraceMiddleware($trace, 'global');
        $local = new TraceMiddleware($trace, 'route');
        $route = (new Route('GET', '/test', Support\fixture_handler(...), new Container()))
            ->middleware($local);

        $route->run($this->request('GET', '/test'), [], [$global]);

        self::assertSame(
            ['global:before', 'route:before', 'route:after', 'global:after'],
            $trace->events
        );
    }

    public function testMiddlewareCanShortCircuitTheHandler(): void
    {
        $middleware = new class implements \Poletti\Framework\Routing\Middleware {
            public function handle(Request $request, callable $next): Response
            {
                return new Response('blocked', 403);
            }
        };
        $route = (new Route('GET', '/test', 'function_that_must_not_run', new Container()))
            ->middleware($middleware);

        [$body, $status] = ResponseCapture::capture(
            $route->run($this->request('GET', '/test'), [])
        );

        self::assertSame('blocked', $body);
        self::assertSame(403, $status);
    }

    private function request(string $method, string $uri): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;

        return new Request();
    }
}
