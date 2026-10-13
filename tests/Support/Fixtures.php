<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Support;

use Poletti\Framework\Routing\Middleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;

interface FixtureServiceInterface
{
    public function value(): string;
}

final class FixtureService implements FixtureServiceInterface
{
    public function value(): string
    {
        return 'fixture-service';
    }
}

final class FixtureConsumer
{
    public function __construct(
        public FixtureServiceInterface $service
    ) {}
}

final class FixtureWithoutConstructor {}

abstract class FixtureAbstract {}

final class FixtureWithScalarConstructor
{
    public function __construct(public string $value) {}
}

final class FixtureController
{
    public function index(Request $request, array $params): Response
    {
        return new Response('controller:' . ($params['id'] ?? 'none'));
    }

    public function show(Request $request, array $params): Response
    {
        return new Response('show:' . ($params['id'] ?? 'none'));
    }
}

final class TraceMiddleware implements Middleware
{
    public function __construct(
        private object $trace,
        private string $name
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $this->trace->events[] = $this->name . ':before';
        $response = $next($request);
        $this->trace->events[] = $this->name . ':after';

        return $response;
    }
}

function fixture_handler(Request $request, array $params): Response
{
    return new Response(
        'function:' . $request->method() . ':' . ($params['id'] ?? 'none')
    );
}

final class ResponseCapture
{
    /** @return array{0: string, 1: int|false} */
    public static function capture(Response $response): array
    {
        ob_start();

        try {
            $response->send();
            $body = (string) ob_get_contents();
            $status = http_response_code();
        } finally {
            ob_end_clean();
        }

        return [$body, $status];
    }
}
