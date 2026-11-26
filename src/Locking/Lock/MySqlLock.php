<?php

declare(strict_types=1);

namespace Poletti\Framework\Locking\Lock;

use PDO;
use Poletti\Framework\Locking\LockInterface;
use RuntimeException;

final class MySqlLock implements LockInterface
{
    private bool $released = false;

    public function __construct(
        private PDO $pdo,
        private string $key
    ) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT RELEASE_LOCK(?)'
        );

        $stmt->execute([$this->key]);

        $result = $stmt->fetchColumn();

        if ($result !== 1 && $result !== '1') {
            throw new RuntimeException(
                'Could not release lock.'
            );
        }

        $this->released = true;
    }
} 