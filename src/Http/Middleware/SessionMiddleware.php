<?php

namespace Poletti\Framework\Http\Middleware;

use Poletti\Framework\Routing\Middleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use Poletti\Framework\Session\SessionInterface;

final class SessionMiddleware implements Middleware
{
    public function __construct(
        private SessionInterface $session
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $this->session->start();

        return $next($request);
    }
}