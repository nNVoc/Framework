<?php

namespace Poletti\Framework\Exceptions;

final class UnsupportedMediaTypeException extends HttpException
{
    public function __construct(string $message = 'Unsupported Media Type', ?\Throwable $previous = null)
    {
        parent::__construct(415, $message, $previous);
    }
}
