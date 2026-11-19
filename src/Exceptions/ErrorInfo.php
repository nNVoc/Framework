<?php

namespace Poletti\Framework\Exceptions;

final readonly class ErrorInfo
{
    public function __construct(
        public int $status,
        public string $message,
        public array $headers = [],
    ) {}
}