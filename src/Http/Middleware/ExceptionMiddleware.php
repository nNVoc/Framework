<?php

namespace Poletti\Framework\Http\Middleware;

use Poletti\Framework\Exceptions\ExceptionHandler;
use Poletti\Framework\Routing\Middleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use Throwable;

final class ExceptionMiddleware implements Middleware
{
    public function __construct(
        private ExceptionHandler $exceptionHandler
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $exception) {
            return $this->exceptionHandler->handle($exception, $request);
        }
    }
}