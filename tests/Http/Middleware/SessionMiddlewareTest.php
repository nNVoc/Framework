<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Poletti\Framework\Http\Middleware\SessionMiddleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use Poletti\Framework\Session\SessionInterface;
use RuntimeException;

final class SessionMiddlewareTest extends TestCase
{
    public function testSessionStartsBeforeNextAndPreservesRequestAndResponse(): void
    {
        $started = false;
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('start')->willReturnCallback(
            static function () use (&$started): void {
                $started = true;
            }
        );
        $request = new Request([]);
        $response = new Response('Created', 201);

        self::assertSame($response, (new SessionMiddleware($session))->handle(
            $request,
            function (Request $received) use (&$started, $request, $response): Response {
                self::assertTrue($started, 'The session must be started before downstream middleware.');
                self::assertSame($request, $received);
                return $response;
            }
        ));
    }

    public function testSessionStartupFailureDoesNotReachNext(): void
    {
        $failure = new RuntimeException('Could not start session.');
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('start')->willThrowException($failure);

        $this->expectExceptionObject($failure);
        (new SessionMiddleware($session))->handle(new Request([]), function (): Response {
            self::fail('Session startup failure must stop the chain.');
        });
    }

    public function testDownstreamFailurePropagatesToOuterMiddleware(): void
    {
        $failure = new RuntimeException('Controller failed');
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('start');

        $this->expectExceptionObject($failure);
        (new SessionMiddleware($session))->handle(new Request([]), static fn (): Response => throw $failure);
    }
}
