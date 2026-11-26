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

    public function testHeadersAreReadCaseInsensitivelyFromServer(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'csrf-token';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $_SERVER['CONTENT_LENGTH'] = '42';

        $request = new Request();
        self::assertSame('csrf-token', $request->header('x-CsRf-ToKeN'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame('42', $request->header('Content-Length'));
        self::assertNull($request->header('X-Missing'));
    }

    public function testExplicitServerIsUsedConsistently(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'global';
        $request = new Request([
            'REMOTE_ADDR' => '192.0.2.1',
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/login?next=home',
            'HTTP_X_CSRF_TOKEN' => 'explicit',
        ]);
        self::assertSame('192.0.2.1', $request->ip());
        self::assertSame('POST', $request->method());
        self::assertSame('/login', $request->path());
        self::assertSame('explicit', $request->header('X-CSRF-Token'));
        self::assertNull((new Request([]))->ip());
    }

    public function testCaptureKeepsServerSnapshot(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = Request::capture();
        $_SERVER['REMOTE_ADDR'] = '192.0.2.2';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        self::assertSame('192.0.2.1', $request->ip());
        self::assertSame('GET', $request->method());
    }

    public function testIpRejectsInvalidValuesAndNormalizesIpv6(): void
    {
        foreach (['', 'invalid', '192.0.2.1, 192.0.2.2', [], 123] as $ip) {
            self::assertNull((new Request(['REMOTE_ADDR' => $ip]))->ip());
        }
        self::assertSame('2001:db8::1', (new Request([
            'REMOTE_ADDR' => '2001:0db8:0000:0000:0000:0000:0000:0001',
        ]))->ip());
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        self::assertSame('192.0.2.1', (new Request())->ip());
    }
}
