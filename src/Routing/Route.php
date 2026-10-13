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

    public function run(
        Request $request,
        array $params,
        array $globalMiddlewares = []
    ): Response {
        $handler = function (Request $request) use ($params): Response {
            $callback = $this->handler;

            if ($callback instanceof \Closure) {
                return $callback($request, $params);
            }

            if (is_array($callback)) {
                [$class, $method] = $callback;

                if (is_string($class)) {
                    $class = $this->container->get($class);
                }

                return $class->{$method}($request, $params);
            }

            if (is_string($callback) && str_contains($callback, '::')) {
                [$class, $method] = explode('::', $callback, 2);

                if (!class_exists($class)) {
                    throw new \RuntimeException(
                        "Controller {$class} does not exist."
                    );
                }

                $controller = $this->container->get($class);

                if (!is_callable([$controller, $method])) {
                    throw new \RuntimeException(
                        "Handler {$class}::{$method} is not callable."
                    );
                }

                return $controller->{$method}($request, $params);
            }

            if (is_string($callback) && class_exists($callback)) {
                $controller = $this->container->get($callback);

                if (!is_callable([$controller, 'index'])) {
                    throw new \RuntimeException(
                        "Handler {$callback}::index is not callable."
                    );
                }

                return $controller->index($request, $params);
            }

            if (is_string($callback) && function_exists($callback)) {
                return $callback($request, $params);
            }

            throw new \RuntimeException('Invalid route handler.');
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