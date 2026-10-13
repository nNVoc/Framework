<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Poletti\Framework\Container\Container;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use Poletti\Framework\Routing\Router;
use Poletti\Framework\Tests\Support\ResponseCapture;
use Poletti\Framework\Tests\Support\TraceMiddleware;
use Poletti\Framework\Tests\Support;

final class RouterTest extends TestCase
{
    private array $originalServer;

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        http_response_code(200);
    }

    public function testEachSupportedHttpMethodCanBeRegisteredAndDispatched(): void
    {
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

        foreach ($methods as $method) {
            $router = new Router(new Container());
            $router->{strtolower($method)}('/verbs', $this->handler());

            [$body, $status] = ResponseCapture::capture(
                $router->dispatch($this->request($method, '/verbs'))
            );

            self::assertSame('function:' . $method . ':none', $body, $method);
            self::assertSame(200, $status, $method);
        }
    }

    public function testDispatchReturns404WhenNoPathMatches(): void
    {
        $router = new Router(new Container());
        $router->get('/known', $this->handler());

        [$body, $status] = ResponseCapture::capture(
            $router->dispatch($this->request('GET', '/unknown'))
        );

        self::assertSame('404 Not Found', $body);
        self::assertSame(404, $status);
    }

    public function testDispatchReturns405WhenPathMatchesButMethodDoesNot(): void
    {
        $router = new Router(new Container());
        $router->get('/users/{id}', $this->handler());

        [$body, $status] = ResponseCapture::capture(
            $router->dispatch($this->request('POST', '/users/42'))
        );

        self::assertSame('405 Method Not Allowed', $body);
        self::assertSame(405, $status);
    }

    public function testDispatchCanChooseAnotherRouteWithTheSamePathAndCorrectMethod(): void
    {
        $router = new Router(new Container());
        $router->get('/users', $this->handler());
        $router->post('/users', $this->handler());

        [$body, $status] = ResponseCapture::capture(
            $router->dispatch($this->request('POST', '/users'))
        );

        self::assertSame('function:POST:none', $body);
        self::assertSame(200, $status);
    }

    public function testGlobalMiddlewareRunsOnMatchedRoutes(): void
    {
        $trace = (object) ['events' => []];
        $router = new Router(new Container());
        $router->middleware(new TraceMiddleware($trace, 'global'));
        $router->get('/test', $this->handler());

        $router->dispatch($this->request('GET', '/test'));

        self::assertSame(['global:before', 'global:after'], $trace->events);
    }

    public function testMiddlewareRegistrationReturnsTheRouterForChaining(): void
    {
        $router = new Router(new Container());
        $middleware = new TraceMiddleware((object) ['events' => []], 'global');

        self::assertSame($router, $router->middleware($middleware));
    }


    private function handler(): string
    {
        return 'Poletti\\Framework\\Tests\\Support\\fixture_handler';
    }

    private function request(string $method, string $uri): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;

        return new Request();
    }
}
