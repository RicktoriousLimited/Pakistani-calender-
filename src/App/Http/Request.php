<?php
namespace SHUTDOWN\App\Http;

class Request
{
    private string $method;

    /** @var array<string, mixed> */
    private array $query;

    /** @var array<string, mixed> */
    private array $server;

    private ?string $rawBody;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $server
     */
    public function __construct(string $method = 'GET', array $query = [], array $server = [], ?string $rawBody = null)
    {
        $this->method = strtoupper($method ?: 'GET');
        $this->query = $query;
        $this->server = $server;
        $this->rawBody = $rawBody;
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        return new self(is_string($method) ? $method : 'GET', $_GET, $_SERVER);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function route(): string
    {
        $route = $this->query['route'] ?? 'schedule';
        if (!is_string($route)) {
            return 'schedule';
        }
        $route = trim($route);
        return $route === '' ? 'schedule' : $route;
    }

    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function queryAll(): array
    {
        return $this->query;
    }

    public function rawBody(): string
    {
        if ($this->rawBody !== null) {
            return $this->rawBody;
        }
        $content = file_get_contents('php://input');
        $this->rawBody = $content === false ? '' : $content;
        return $this->rawBody;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonBody(bool $required = true): array
    {
        $raw = $this->rawBody();
        if ($raw === '') {
            if ($required) {
                throw new HttpException(400, 'Empty payload');
            }
            return [];
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException(400, 'Invalid JSON payload', ['detail' => json_last_error_msg()]);
        }
        if (!is_array($decoded)) {
            throw new HttpException(400, 'Invalid JSON payload');
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
