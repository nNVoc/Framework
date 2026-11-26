<?php

namespace Poletti\Framework\Exceptions;

use Poletti\Framework\Locking\Exceptions\LockTimeoutException;
use Poletti\Framework\Routing\Request;
use Poletti\Framework\Routing\Response;
use Throwable;

class ExceptionHandler
{
    public function handle(
        Throwable $exception,
        ?Request $request = null
    ): Response {
        $error = $this->map($exception);

        if ($error->status >= 500) {
            error_log(
                'Unhandled ' . $exception::class
                . ' in ' . $exception->getFile()
                . ':' . $exception->getLine()
                . PHP_EOL
                . $exception->getTraceAsString()
            );
        }

        return $this->render($error, $request);
    }

    protected function map(Throwable $exception): ErrorInfo
    {
        return match (true) {
            $exception instanceof HttpException => new ErrorInfo(
                status: $exception->getStatusCode(),
                message: $exception->getStatusCode() >= 500
                    ? 'Internal Server Error'
                    : $exception->getMessage()
            ),

            $exception instanceof LockTimeoutException => new ErrorInfo(
                status: 503,
                message: 'Service Unavailable'
            ),

            default => new ErrorInfo(
                status: 500,
                message: 'Internal Server Error'
            )
        };
    }

    protected function render(
        ErrorInfo $error,
        ?Request $request
    ): Response {
        return new Response(
            json_encode(['message' => $error->message], JSON_THROW_ON_ERROR),
            $error->status,
            ['Content-Type' => 'application/json'] + $error->headers
        );
    }
}