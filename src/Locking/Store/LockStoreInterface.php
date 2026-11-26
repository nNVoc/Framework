<?php

declare(strict_types=1);

namespace Poletti\Framework\Locking\Store;

use Poletti\Framework\Locking\LockInterface;

interface LockStoreInterface
{
    public function acquire(
        string $key,
        int $timeout = 10
    ): LockInterface;
}