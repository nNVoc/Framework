<?php

namespace Poletti\Framework\Exceptions;

final class ConflictException extends HttpException
{
    public function __construct(string $message = 'Conflict', ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, $previous);
    }
}
