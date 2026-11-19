<?php

namespace Poletti\Framework\Tests\Exceptions;

use PHPUnit\Framework\TestCase;
use Poletti\Framework\Exceptions\ExceptionHandler;
use Poletti\Framework\Exceptions\NotFoundException;
use Poletti\Framework\Routing\Response;
use RuntimeException;

final class ExceptionHandlerTest extends TestCase
{
    public function testHttpExceptionReturnsCorrectStatus(): void
    {
        $handler = new ExceptionHandler();

        $response = $handler->handle(new NotFoundException());

        [$status, $body] = $this->capture($response);

        $this->assertSame(404, $status);
        $this->assertArrayHasKey('message', $body);
    }

    public function testUnexpectedExceptionReturns500(): void
    {
        $handler = new ExceptionHandler();

        $response = $handler->handle(
            new RuntimeException('Sensitive database information')
        );

        [$status, $body] = $this->capture($response);

        $this->assertSame(500, $status);
        $this->assertSame(
            'Internal Server Error',
            $body['message']
        );
    }

    private function capture(Response $response): array
    {
        ob_start();

        try {
            $response->send();

            return [
                http_response_code(),
                json_decode(
                    ob_get_contents(),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                )
            ];
        } finally {
            ob_end_clean();
        }
    }
}