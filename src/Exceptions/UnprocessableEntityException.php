<?php

namespace Poletti\Framework\Exceptions;

final class UnprocessableEntityException extends HttpException
{
    public function __construct(string $message = 'Unprocessable Content', ?\Throwable $previous = null)
    {
        parent::__construct(422, $message, $previous);
    }
}
