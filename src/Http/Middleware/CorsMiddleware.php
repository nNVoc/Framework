<?php

namespace Poletti\Framework\Http\Middleware;

use InvalidArgumentException;
use Poletti\Framework\Routing\Middleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;

final class CorsMiddleware implements Middleware
{
    public function __construct(
        private array $allowedOrigins = [],
        private array $allowedMethods = ['GET', 'POST'],
        private array $allowedHeaders = ['Content-Type', 'Authorization', 'X-CSRF-Token'],
        private bool $allowCredentials = false,
    ) {
        foreach ($this->allowedMethods as $method) {
            if ($method !== strtoupper($method)) {
                throw new InvalidArgumentException(
                    "HTTP method must be uppercase: {$method}"
                );
            }
        }
    }

    public function handle(Request $request, callable $next): Response
    {
        $origin = $request->header('Origin');
        
        $originAllowed = in_array(
            $origin,
            $this->allowedOrigins,
            true
        );

        $isPreflight = $request->method() === 'OPTIONS'
            && $origin !== null
            && $request->header('Access-Control-Request-Method') !== null;

        if ($isPreflight) {
            if (!$originAllowed) {
                return new Response('', 403, [
                    'Vary' => 'Origin',
                ]);
            }

            $requestedMethod = $request->header('Access-Control-Request-Method');
            $requestedHeaders = $request->header('Access-Control-Request-Headers');

            $methodAllowed = in_array(
                $requestedMethod,
                $this->allowedMethods,
                true
            );

            $requestedHeaders = $requestedHeaders !== null
                ? array_map('trim', explode(',', $requestedHeaders))
                : [];

            $allowedHeaders = array_map('strtolower', $this->allowedHeaders);
            $requestedHeaders = array_map('strtolower', $requestedHeaders);

            $headersAllowed = empty(array_diff($requestedHeaders, $allowedHeaders));

            if ($methodAllowed && $headersAllowed) {
                return new Response(
                    '',
                    204,
                    [
                        'Access-Control-Allow-Origin' => $origin,
                        'Access-Control-Allow-Methods' => $requestedMethod,
                        'Access-Control-Allow-Headers' => implode(', ', $requestedHeaders),
                        'Vary' => 'Origin',
                        ...($this->allowCredentials
                            ? ['Access-Control-Allow-Credentials' => 'true']
                            : []),
                    ]
                );
            }

            return new Response('', 403, [
                'Vary' => 'Origin',
            ]);
        }

        $response = $next($request);

        if ($origin !== null) {
            $response->appendHeader('Vary', 'Origin');
        }

        if ($originAllowed) {
            $response->header('Access-Control-Allow-Origin', $origin);

            if ($this->allowCredentials) {
                $response->header('Access-Control-Allow-Credentials', 'true');
            }
        }

        return $response;
    }
}
