<?php

namespace Poletti\Framework\Routing;

interface Middleware
{
    public function handle(Request $request, callable $next): Response;
}