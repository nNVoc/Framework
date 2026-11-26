<?php

declare(strict_types=1);

namespace Poletti\Framework\RateLimiting;

final class RateLimiter
{
    public function __construct(
        private RateLimitStoreInterface $store
    ) {}

    public function attempt(
        string $key,
        int $maxAttempts,
        int $decaySeconds
    ): RateLimitResult {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException(
                'Max attempts must be greater than zero.'
            );
        }

        if ($decaySeconds < 1) {
            throw new \InvalidArgumentException(
                'Decay seconds must be greater than zero.'
            );
        }

        return $this->store->attempt(
            $key,
            $maxAttempts,
            $decaySeconds
        );
    }

    public function remaining(
        string $key,
        int $maxAttempts,
        int $decaySeconds
    ): int {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException(
                'Max attempts must be greater than zero.'
            );
        }

        if ($decaySeconds < 1) {
            throw new \InvalidArgumentException(
                'Decay seconds must be greater than zero.'
            );
        }
        
        return $this->store->remaining(
            $key,
            $maxAttempts,
            $decaySeconds
        );
    }

    public function reset(string $key): void
    {
        $this->store->reset($key);
    }
}