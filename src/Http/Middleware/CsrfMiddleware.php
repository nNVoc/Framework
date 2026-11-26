<?php

namespace Poletti\Framework\Http\Middleware;

use Poletti\Framework\Exceptions\CsrfException;
use Poletti\Framework\Routing\Middleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use Poletti\Framework\Security\CsrfTokenManager;

final class CsrfMiddleware implements Middleware
{
    public function __construct(
        private CsrfTokenManager $csrf
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $token = $request->header('X-CSRF-Token');

        if (
            !is_string($token) ||
            !$this->csrf->validate($token)
        ) {
            throw new CsrfException();
        }

        return $next($request);
    }
}
