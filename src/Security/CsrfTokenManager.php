<?php

declare(strict_types=1);

namespace Poletti\Framework\Security;

use Poletti\Framework\Session\SessionInterface;

final class CsrfTokenManager
{
    public function __construct(
        private SessionInterface $session
    ) {}

    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));

        $this->session->set('_csrf', $token);

        return $token;
    }

    public function getToken(): string
    {
        $token = $this->session->get('_csrf');

        if (!$this->isValidToken($token)) {
            return $this->generateToken();
        }

        return $token;
    }

    public function validate(string $token): bool
    {
        $expected = $this->session->get('_csrf');

        return $this->isValidToken($expected)
            && hash_equals($expected, $token);
    }

    private function isValidToken(mixed $token): bool
    {
        return is_string($token)
            && preg_match('/\A[0-9a-f]{64}\z/', $token) === 1;
    }
}
