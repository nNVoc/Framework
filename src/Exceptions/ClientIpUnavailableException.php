<?php

namespace Poletti\Framework\Exceptions;

final class ClientIpUnavailableException extends HttpException
{
    public function __construct(string $message = 'Client IP unavailable.', ?\Throwable $previous = null)
    {
        parent::__construct(500, $message, $previous);
    }
}
