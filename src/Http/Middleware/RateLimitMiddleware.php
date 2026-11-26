<?php

namespace Poletti\Framework\Http\Middleware;

use Poletti\Framework\Exceptions\ClientIpUnavailableException;
use Poletti\Framework\RateLimiting\RateLimiter;
use Poletti\Framework\Routing\Middleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;

final class RateLimitMiddleware implements Middleware
{
    public function __construct(
        private RateLimiter $limiter,
        private int $maxAttempts,
        private int $decaySeconds,
        private string $scope
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $ip = $request->ip();

        if ($ip === null) {
            throw new ClientIpUnavailableException();
        }

        $key = $this->scope . ':ip:' . $ip;

        $result = $this->limiter->attempt(
            $key,
            $this->maxAttempts,
            $this->decaySeconds
        );

        if (!$result->allowed) {
            return new Response(
                'Too Many Requests',
                429,
                [
                    'Retry-After' => (string) $result->retryAfter
                ]
            );
        }
        
        return $next($request); 
    }
}