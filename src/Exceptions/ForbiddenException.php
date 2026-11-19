<?php

namespace Poletti\Framework\Exceptions;

final class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden', ?\Throwable $previous = null)
    {
        parent::__construct(403, $message, $previous);
    }
}
