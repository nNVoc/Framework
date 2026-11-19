<?php

namespace Poletti\Framework\Exceptions;

final class MethodNotAllowedException extends HttpException
{
    public function __construct(string $message = 'Method Not Allowed', ?\Throwable $previous = null)
    {
        parent::__construct(405, $message, $previous);
    }
}
