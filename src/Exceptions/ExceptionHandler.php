<?php

namespace Poletti\Framework\Exceptions;

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
            );
        }

        return $this->render($error, $request);
    }

    protected function map(Throwable $exception): ErrorInfo
    {
        if ($exception instanceof HttpException) {
            $status = $exception->getStatusCode();

            return new ErrorInfo(
                status: $status,
                message: $status >= 500
                    ? 'Internal Server Error'
                    : $exception->getMessage()
            );
        }

        return new ErrorInfo(500, 'Internal Server Error');
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