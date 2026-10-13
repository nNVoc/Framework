<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Poletti\Framework\Routing\Request;

final class RequestTest extends TestCase
{
    private array $originalServer;
    private array $originalGet;

    protected function setUp(): void
    {
        $this->originalServer = $_SERVER;
        $this->originalGet = $_GET;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_GET = $this->originalGet;
    }

    public function testMethodReturnsTheServerRequestMethod(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';

        self::assertSame('PATCH', (new Request())->method());
    }

    public function testMethodDefaultsToAnEmptyString(): void
    {
        unset($_SERVER['REQUEST_METHOD']);

        self::assertSame('', (new Request())->method());
    }

    public function testPathReturnsOnlyTheUriPathAndIgnoresTheQueryString(): void
    {
        $_SERVER['REQUEST_URI'] = '/users/42?include=posts';

        self::assertSame('/users/42', (new Request())->path());
    }

    public function testPathDefaultsToRoot(): void
    {
        unset($_SERVER['REQUEST_URI']);

        self::assertSame('/', (new Request())->path());
    }

    public function testQueryReturnsAStringValue(): void
    {
        $_GET['page'] = '3';

        self::assertSame('3', (new Request())->query('page'));
    }

    public function testQueryReturnsNullWhenTheKeyIsMissing(): void
    {
        unset($_GET['absent']);

        self::assertNull((new Request())->query('absent'));
    }

    public function testBodyFallsBackToAnEmptyArrayWhenInputIsEmpty(): void
    {
        self::assertSame([], (new Request())->body());
    }
}
