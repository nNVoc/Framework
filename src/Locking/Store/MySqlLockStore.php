<?php

declare(strict_types=1);

namespace Poletti\Framework\Locking\Store;

use InvalidArgumentException;
use PDO;
use Poletti\Framework\Locking\Exceptions\LockTimeoutException;
use Poletti\Framework\Locking\Lock\MySqlLock;
use Poletti\Framework\Locking\LockInterface;
use RuntimeException;

final class MySqlLockStore implements LockStoreInterface
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function acquire(
        string $key,
        int $timeout = 10
    ): LockInterface {
        if ($timeout < 0) {
            throw new InvalidArgumentException(
                'Lock timeout must be non-negative.'
            );
        }
        
        $hash = hash('sha256', $key);

        $stmt = $this->pdo->prepare(
            'SELECT GET_LOCK(?, ?)'
        );

        $stmt->execute([$hash, $timeout]);

        $result = $stmt->fetchColumn();

        if ($result === 0 || $result === '0') {
            throw new LockTimeoutException(
                "Timed out acquiring lock: {$key}"
            );
        }

        if ($result !== 1 && $result !== '1') {
            throw new RuntimeException(
                'Lock acquisition failed.'
            );
        }

        return new MySqlLock($this->pdo, $hash);
    }
}