<?php

namespace Poletti\Framework\Routing;

use Poletti\Framework\Container\Container;

class Route
{
    private array $middlewares = [];

    public function __construct(
        private string $method,
        private string $path,
        private mixed $handler,
        private Container $container
    ) {}

    public function match(string $method, string $path): array|false|null
    {
        $pattern = preg_replace(
            '#\{([^}]+)\}#',
            '([^/]+)',
            $this->path
        );

        $pattern = '#^' . $pattern . '$#';

        if (!preg_match($pattern, $path, $matches)) {
            return null;
        }

        if ($this->method !== $method) {
            return false;
        }

        preg_match_all('#\{([^}]+)\}#', $this->path, $names);

        $params = [];

        foreach ($names[1] as $index => $name) {
            $params[$name] = $matches[$index + 1];
        }

        return $params;
    }

    public function middleware(Middleware $middleware): self
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    public function run(Request $request, array $params, array $globalMiddlewares = []): Response
    {
        $handler = function (Request $request) use ($params): Response {
            $parts = explode('::', $this->handler, 2);

            $class = $parts[0];
            $method = $parts[1] ?? 'index';

            if (!class_exists($class)) {
                throw new \RuntimeException(
                    "Controller {$class} does not exist."
                );
            }

            $controller = $this->container->get($class);

            if (!method_exists($controller, $method)) {
                throw new \RuntimeException(
                    "Method {$method} does not exist in {$class}."
                );
            }

            return $controller->{$method}($request, $params);
        };

        $middlewares = array_merge(
            $globalMiddlewares,
            $this->middlewares
        );

        foreach (array_reverse($middlewares) as $middleware) {
            $next = $handler;

            $handler = function (Request $request) use ($middleware, $next): Response {
                return $middleware->handle($request, $next);
            };
        }

        return $handler($request);
    }
}