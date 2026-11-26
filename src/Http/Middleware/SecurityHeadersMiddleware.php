<?php

declare(strict_types=1);

namespace Poletti\Framework\Http\Middleware;

use Poletti\Framework\Routing\Middleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;

final class SecurityHeadersMiddleware implements Middleware
{
    private array $headers;

    public function __construct(array $headers = [])
    {
        $this->headers = array_replace([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-XSS-Protection' => '0',
        ], $headers);
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        foreach ($this->headers as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }
}