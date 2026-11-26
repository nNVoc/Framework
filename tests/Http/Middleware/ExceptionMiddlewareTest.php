<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Http\Middleware;

use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poletti\Framework\Exceptions\ExceptionHandler;
use Poletti\Framework\Exceptions\NotFoundException;
use Poletti\Framework\Http\Middleware\ExceptionMiddleware;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use RuntimeException;
use Throwable;

final class ExceptionMiddlewareTest extends TestCase
{
    public function testSuccessfulResponsePassesThroughWithoutCallingExceptionHandler(): void
    {
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects(self::never())->method('handle');
        $request = new Request([]);
        $response = new Response('Created', 201, ['X-App' => 'test']);

        self::assertSame($response, (new ExceptionMiddleware($handler))->handle(
            $request,
            function (Request $received) use ($request, $response): Response {
                self::assertSame($request, $received);
                return $response;
            }
        ));
    }

    #[DataProvider('throwables')]
    public function testDelegatesThrowableAndOriginalRequestToHandler(Throwable $exception): void
    {
        $request = new Request([]);
        $response = new Response('Handled', 500);
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects(self::once())->method('handle')
            ->with(self::identicalTo($exception), self::identicalTo($request))
            ->willReturn($response);

        self::assertSame($response, (new ExceptionMiddleware($handler))->handle(
            $request,
            static fn (): Response => throw $exception
        ));
    }

    public static function throwables(): iterable
    {
        yield 'HTTP exception' => [new NotFoundException()];
        yield 'runtime exception' => [new RuntimeException('Database failed')];
        yield 'PHP error' => [new Error('Controller failed')];
    }

    public function testFailureInExceptionHandlerPropagatesWithoutRecursiveHandling(): void
    {
        $original = new RuntimeException('Original failure');
        $renderFailure = new RuntimeException('Rendering failed');
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects(self::once())->method('handle')->willThrowException($renderFailure);

        $this->expectExceptionObject($renderFailure);
        (new ExceptionMiddleware($handler))->handle(new Request([]), static fn (): Response => throw $original);
    }
}
