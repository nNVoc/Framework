<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Routing;

use JsonException;
use PHPUnit\Framework\TestCase;
use Poletti\Framework\Routing\Response;
use Poletti\Framework\Tests\Support\ResponseCapture;

final class ResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        http_response_code(200);
    }

    public function testSendOutputsTheBodyAndSetsTheStatusCode(): void
    {
        [$body, $status] = ResponseCapture::capture(
            new Response('Created', 201)
        );

        self::assertSame('Created', $body);
        self::assertSame(201, $status);
    }

    public function testDefaultResponseHasAnEmptyBodyAndStatus200(): void
    {
        [$body, $status] = ResponseCapture::capture(new Response());

        self::assertSame('', $body);
        self::assertSame(200, $status);
    }

    public function testJsonEncodesDataAndPreservesUnicode(): void
    {
        $response = Response::json(['message' => 'Olá'], 202);
        [$body, $status] = ResponseCapture::capture($response);

        self::assertSame('{"message":"Olá"}', $body);
        self::assertSame(202, $status);
    }

    public function testJsonThrowsWhenDataCannotBeEncoded(): void
    {
        $this->expectException(JsonException::class);

        Response::json(['resource' => fopen('php://memory', 'r')]);
    }
}
