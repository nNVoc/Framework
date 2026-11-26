<?php

namespace Poletti\Framework\Routing;

class Response
{
    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = []
    ) {}

    public function send() : void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        echo $this->body;
    }

    public static function json(
        array $data,
        int $status = 200
    ): self {
        return new self(
            body: json_encode(
                $data,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ),
            status: $status,
            headers: [
                'Content-Type' => 'application/json'
            ]
        );
    }

    public function header(string $name, string $value): self
    {
        foreach ($this->headers as $key => $_) {
            if (strcasecmp($key, $name) === 0) {
                $this->headers[$key] = $value;

                return $this;
            }
        }

        $this->headers[$name] = $value;

        return $this;
    }

    public function appendHeader(string $name, string $value): self
    {
        foreach ($this->headers as $key => $_) {
            if (strcasecmp($key, $name) === 0) {
                $this->headers[$key] .= ', ' . $value;

                return $this;
            }
        }

        return $this->header($name, $value);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }
}
