<?php

declare(strict_types=1);

namespace Poletti\Framework\RateLimiting;

interface RateLimitStoreInterface
{
    public function attempt(string $key, int $maxAttempts, int $decaySeconds): RateLimitResult;

    public function hit(string $key, int $decaySeconds): void;
    
    public function remaining(string $key, int $maxAttempts, int $decaySeconds): int;

    public function reset(string $key): void;
}