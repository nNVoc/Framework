<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Session;

use PHPUnit\Framework\TestCase;
use Poletti\Framework\Container\Container;
use Poletti\Framework\Exceptions\ExceptionHandler;
use Poletti\Framework\Http\Middleware\CorsMiddleware;
use Poletti\Framework\Http\Middleware\CsrfMiddleware;
use Poletti\Framework\Http\Middleware\ExceptionMiddleware;
use Poletti\Framework\Http\Middleware\SessionMiddleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use Poletti\Framework\Routing\Router;
use Poletti\Framework\Security\CsrfTokenManager;
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

    public function testCsrfFlowThroughRouterWithRealSessionAndCors(): void
    {
        $server = $_SERVER;

        try {
            $csrf = new CsrfTokenManager($this->session);
            $router = new Router(new Container());
            $router->middleware(new CorsMiddleware(
                allowedOrigins: ['https://app.example.com'],
                allowCredentials: true,
            ));
            $router->middleware(new ExceptionMiddleware(
                new ExceptionHandler()
            ));
            $router->middleware(new SessionMiddleware($this->session));
            $router->middleware(new CsrfMiddleware($csrf));
            $router->get('/csrf', fn () => Response::json(['token' => $csrf->getToken()]));
            $writes = 0;
            $router->post('/write', function () use (&$writes): Response {
                $writes++;
                return new Response('', 201, ['Vary' => 'Accept-Encoding']);
            });

            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/csrf';
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
            $request = new Request();
            self::assertSame(200, $router->dispatch($request)->status());
            $token = $csrf->getToken();

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/write';
            $_SERVER['HTTP_ORIGIN'] = 'https://app.example.com';
            self::assertSame(403, $router->dispatch($request)->status());
            $_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('b', 64);
            self::assertSame(403, $router->dispatch($request)->status());
            self::assertSame(0, $writes);

            $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
            $response = $router->dispatch($request);
            self::assertSame(201, $response->status());
            self::assertSame(1, $writes);
            self::assertSame('Accept-Encoding, Origin', $response->headers()['Vary']);
            self::assertSame('true', $response->headers()['Access-Control-Allow-Credentials']);

            $csrf->generateToken();
            self::assertSame(403, $router->dispatch($request)->status());
            self::assertSame(1, $writes);

            $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
            $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] = 'POST';
            $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] = 'content-type, x-csrf-token';
            unset($_SERVER['HTTP_X_CSRF_TOKEN']);
            self::assertSame(204, $router->dispatch($request)->status());

            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/missing';
            self::assertSame(404, $router->dispatch($request)->status());
            $_SERVER['REQUEST_URI'] = '/write';
            self::assertSame(405, $router->dispatch($request)->status());
        } finally {
            $_SERVER = $server;
        }
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
