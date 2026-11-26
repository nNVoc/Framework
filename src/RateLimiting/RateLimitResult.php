<?php

namespace Poletti\Framework\RateLimiting;

final class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $retryAfter
    ) {}
}