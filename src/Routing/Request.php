<?php

namespace Poletti\Framework\Routing;

class Request 
{
    public function __construct(
        private ?array $server = null,
    ) {}

    public function method() : string 
    {
        return ($this->server ?? $_SERVER)['REQUEST_METHOD'] ?? '';
    }
    
    public function path() : string 
    {
        return parse_url(($this->server ?? $_SERVER)['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    }

    public function query(string $key) : ?string 
    {
        return $_GET[$key] ?? null;
    }
    public function body(): array
    {
        $content = file_get_contents('php://input');

        return json_decode($content, true) ?? [];
    }

    public function header(string $name): ?string
    {
        $headers = $this->server === null && function_exists('getallheaders') ? (getallheaders() ?: []) : [];

        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        $key = strtoupper(str_replace('-', '_', $name));
        $serverKey = in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)
            ? $key
            : 'HTTP_' . $key;
        $value = ($this->server ?? $_SERVER)[$serverKey] ?? null;

        return is_string($value) ? $value : null;
    }

    public static function capture(): self
    {
        return new self(
            server: $_SERVER
        );
    }

    public function ip(): ?string
    {
        $ip = ($this->server ?? $_SERVER)['REMOTE_ADDR'] ?? null;

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false
            ? inet_ntop(inet_pton($ip))
            : null;
    }
}
