<?php

namespace Poletti\Framework\Exceptions;

final class TooManyRequestsException extends HttpException
{
    public function __construct(string $message = 'Too Many Requests', ?\Throwable $previous = null)
    {
        parent::__construct(429, $message, $previous);
    }
}
