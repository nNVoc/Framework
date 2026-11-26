<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poletti\Framework\Exceptions\CsrfException;
use Poletti\Framework\Http\Middleware\CsrfMiddleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use Poletti\Framework\Security\CsrfTokenManager;
use Poletti\Framework\Session\SessionInterface;

final class CsrfMiddlewareTest extends TestCase
{
    #[DataProvider('safeMethods')]
    public function testSafeMethodsDoNotRequireSessionOrToken(string $method): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::never())->method('get');
        $request = $this->request($method, null);
        $response = new Response();

        self::assertSame($response, (new CsrfMiddleware(new CsrfTokenManager($session)))
            ->handle($request, fn (Request $received) => $received === $request ? $response : new Response('', 500)));
    }

    public static function safeMethods(): iterable
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('protectedRequests')]
    public function testProtectedRequestsRequireMatchingToken(string $method, ?string $token, bool $valid): void
    {
        $expected = str_repeat('a', 64);
        $session = $this->createStub(SessionInterface::class);
        $session->method('get')->willReturn($expected);
        $request = $this->request($method, $token);
        $middleware = new CsrfMiddleware(new CsrfTokenManager($session));
        $response = new Response();

        if (!$valid) {
            $this->expectException(CsrfException::class);
        }

        self::assertSame($response, $middleware->handle($request, function (Request $received) use ($request, $valid, $response): Response {
            self::assertTrue($valid, 'Invalid token must not reach the handler.');
            self::assertSame($request, $received);
            return $response;
        }));
    }

    public static function protectedRequests(): iterable
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'CUSTOM'] as $method) {
            yield $method . ' valid' => [$method, str_repeat('a', 64), true];
            yield $method . ' missing' => [$method, null, false];
            yield $method . ' empty' => [$method, '', false];
            yield $method . ' invalid' => [$method, str_repeat('b', 64), false];
        }
    }

    #[DataProvider('invalidSessionTokens')]
    public function testRejectsTokenWhenSessionHasNoValidToken(mixed $stored): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('get')->with('_csrf')->willReturn($stored);
        $session->expects(self::never())->method('set');

        $this->expectException(CsrfException::class);
        (new CsrfMiddleware(new CsrfTokenManager($session)))->handle(
            $this->request('POST', str_repeat('a', 64)),
            function (): Response {
                self::fail('A request without a valid session token must not reach the handler.');
            }
        );
    }

    public static function invalidSessionTokens(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'malformed' => ['not-a-token'];
        yield 'wrong type' => [123];
    }

    private function request(string $method, ?string $token): Request
    {
        return new class($method, $token) extends Request {
            public function __construct(private string $verb, private ?string $token) {}

            public function method(): string
            {
                return $this->verb;
            }

            public function header(string $name): ?string
            {
                return $name === 'X-CSRF-Token' ? $this->token : null;
            }
        };
    }
}
