<?php

declare(strict_types=1);

namespace Poletti\Framework\Locking;

use Poletti\Framework\Locking\Store\LockStoreInterface;

final class LockManager
{
    public function __construct(
        private LockStoreInterface $store
    ) {}

    public function acquire(
        string $key,
        int $timeout = 10
    ): LockInterface {
        return $this->store->acquire($key, $timeout);
    } 

    public function synchronized(
        string $key,
        callable $callback,
        int $timeout = 10
    ): mixed {
        $lock = $this->acquire($key, $timeout);

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}