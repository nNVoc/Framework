<?php

declare(strict_types=1);

namespace Poletti\Framework\Locking;

interface LockInterface
{
    public function release(): void;
}