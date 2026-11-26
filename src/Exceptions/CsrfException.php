<?php

namespace Poletti\Framework\Exceptions;

final class CsrfException extends HttpException
{
    public function __construct(string $message = 'Invalid CSRF token', ?\Throwable $previous = null)
    {
        parent::__construct(403, $message, $previous);
    }
}