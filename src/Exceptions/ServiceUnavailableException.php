<?php

namespace Poletti\Framework\Exceptions;

final class ServiceUnavailableException extends HttpException
{
    public function __construct(string $message = 'Service Unavailable', ?\Throwable $previous = null)
    {
        parent::__construct(503, $message, $previous);
    }
}
