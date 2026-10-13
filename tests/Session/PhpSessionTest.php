<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Session;

use PHPUnit\Framework\TestCase;
use Poletti\Framework\Session\PhpSession;
use RuntimeException;

final class PhpSessionTest extends TestCase
{
    private PhpSession $session;
    private string $sessionName;
    private string|false $previousUseCookies;
    private string|false $previousCacheLimiter;
    private string|false $previousSavePath;

    protected function setUp(): void
    {
        $this->closeActiveSession();

        $this->previousUseCookies = ini_get('session.use_cookies');
        $this->previousCacheLimiter = ini_get('session.cache_limiter');
        $this->previousSavePath = session_save_path();

        ini_set('session.use_cookies', '1');
        ini_set('session.cache_limiter', '');
        session_save_path(sys_get_temp_dir());

        $this->sessionName = 'TEST' . bin2hex(random_bytes(5));
        $this->session = new PhpSession($this->sessionName);
    }

    protected function tearDown(): void
    {
        $this->closeActiveSession();

        if (is_string($this->previousUseCookies)) {
            ini_set('session.use_cookies', $this->previousUseCookies);
        }
        if (is_string($this->previousCacheLimiter)) {
            ini_set('session.cache_limiter', $this->previousCacheLimiter);
        }
        if (is_string($this->previousSavePath)) {
            session_save_path($this->previousSavePath);
        }
        session_name('PHPSESSID');
        session_id('');
    }

    public function testStartActivatesThePhpSession(): void
    {
        $this->session->start();

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame($this->sessionName, session_name());
    }

    public function testStartCanBeCalledMoreThanOnce(): void
    {
        $this->session->start();
        $id = session_id();
        $this->session->start();

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertSame($id, session_id());
    }

    public function testGetReturnsDefaultWhenKeyIsMissing(): void
    {
        $this->session->start();

        self::assertSame('fallback', $this->session->get('missing', 'fallback'));
    }

    public function testSetGetAndHasHandleValues(): void
    {
        $this->session->start();
        $this->session->set('user_id', 42);

        self::assertTrue($this->session->has('user_id'));
        self::assertSame(42, $this->session->get('user_id'));
    }

    public function testHasReturnsTrueForAKeyWhoseValueIsNull(): void
    {
        $this->session->start();
        $this->session->set('nullable', null);

        self::assertTrue($this->session->has('nullable'));
        self::assertNull($this->session->get('nullable'));
    }

    public function testRemoveDeletesAKey(): void
    {
        $this->session->start();
        $this->session->set('user_id', 42);
        $this->session->remove('user_id');

        self::assertFalse($this->session->has('user_id'));
    }

    public function testClearRemovesAllSessionValues(): void
    {
        $this->session->start();
        $this->session->set('user_id', 42);
        $this->session->set('theme', 'dark');
        $this->session->clear();

        self::assertSame([], $_SESSION);
    }

    public function testRegenerateChangesTheSessionId(): void
    {
        $this->session->start();
        $oldId = session_id();
        $this->session->regenerate();

        self::assertNotSame($oldId, session_id());
    }

    public function testOperationsThrowWhenTheSessionHasNotStarted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Session has not been started.');

        $this->session->get('user_id');
    }

    private function closeActiveSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id('');
        }
    }
}
