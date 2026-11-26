<?php

namespace Poletti\Framework\Routing;

use Poletti\Framework\Container\Container;
use Poletti\Framework\Exceptions\MethodNotAllowedException;
use Poletti\Framework\Exceptions\NotFoundException;

class Router 
{
    private array $routes = [];
    private array $middlewares = [];

    public function __construct(
        private Container $container
    ) {}

    public function get(string $path, mixed $handler): Route
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, mixed $handler): Route
    {
        return $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->add('DELETE', $path, $handler);
    }

    public function head(string $path, mixed $handler): Route
    {
        return $this->add('HEAD', $path, $handler);
    }

    public function options(string $path, mixed $handler): Route
    {
        return $this->add('OPTIONS', $path, $handler);
    }

    private function add(
        string $method,
        string $path,
        mixed $handler
    ): Route
    {
        $route = new Route($method, $path, $handler, $this->container);
    
        $this->routes[] = $route;

        return $route;
    }

    public function middleware(Middleware $middleware): self
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $handler = function (Request $request): Response {
            $methodNotAllowed = false;

            foreach ($this->routes as $route) {
                $result = $route->match(
                    $request->method(),
                    $request->path()
                );

                if ($result === null) {
                    continue;
                }

                if ($result === false) {
                    $methodNotAllowed = true;
                    continue;
                }

                return $route->run($request, $result);
            }

            if ($methodNotAllowed) {
                throw new MethodNotAllowedException();
            }

            throw new NotFoundException();
        };

        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = $handler;

            $handler = function (Request $request) use ($middleware, $next): Response {
                return $middleware->handle($request, $next);
            };
        }

        return $handler($request);
    }
}
