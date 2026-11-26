<?php

declare(strict_types=1);

namespace Poletti\Framework\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poletti\Framework\Security\CsrfTokenManager;
use Poletti\Framework\Session\SessionInterface;

final class CsrfTokenManagerTest extends TestCase
{
    private mixed $stored = null;
    private CsrfTokenManager $csrf;

    protected function setUp(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->with('_csrf')->willReturnCallback(fn () => $this->stored);
        $session->method('set')->with('_csrf', self::isType('string'))
            ->willReturnCallback(function (string $key, string $value): void {
                $this->stored = $value;
            });
        $this->csrf = new CsrfTokenManager($session);
    }

    public function testTokenIsRandomStoredAndReused(): void
    {
        $token = $this->csrf->getToken();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token);
        self::assertSame($token, $this->stored);
        self::assertSame($token, $this->csrf->getToken());
        self::assertTrue($this->csrf->validate($token));
        self::assertFalse($this->csrf->validate(''));
        self::assertFalse($this->csrf->validate(str_repeat('0', 64)));
    }

    public function testRotationInvalidatesTheOldToken(): void
    {
        $old = $this->csrf->getToken();
        $new = $this->csrf->generateToken();

        self::assertNotSame($old, $new);
        self::assertFalse($this->csrf->validate($old));
        self::assertTrue($this->csrf->validate($new));
    }

    public function testTokenFromAnotherSessionIsRejected(): void
    {
        $token = $this->csrf->getToken();
        $otherSession = $this->createStub(SessionInterface::class);
        $otherSession->method('get')->willReturn(str_repeat('a', 64));

        self::assertFalse((new CsrfTokenManager($otherSession))->validate($token));
    }

    #[DataProvider('invalidStoredTokens')]
    public function testInvalidStoredTokenIsRejectedAndReplaced(mixed $stored): void
    {
        $this->stored = $stored;

        self::assertFalse($this->csrf->validate(is_string($stored) ? $stored : ''));
        self::assertSame($stored, $this->stored);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $this->csrf->getToken());
    }

    public static function invalidStoredTokens(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'array' => [[]];
        yield 'integer' => [123];
        yield 'short' => ['abc'];
        yield 'non-hex' => [str_repeat('z', 64)];
    }
}
