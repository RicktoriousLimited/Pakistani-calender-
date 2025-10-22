<?php
namespace SHUTDOWN\App\Http;

class Response
{
    private int $status;

    /** @var array<string, string> */
    private array $headers;

    /** @var array<string, mixed>|null */
    private ?array $jsonData;

    private ?string $body;

    private function __construct(int $status, array $headers = [], ?array $jsonData = null, ?string $body = null)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->jsonData = $jsonData;
        $this->body = $body;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        $headers = array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers);
        return new self($status, $headers, $data, null);
    }

    public static function raw(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $headers, null, $body);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function jsonPayload(): ?array
    {
        return $this->jsonData;
    }

    public function body(): string
    {
        return $this->body ?? '';
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->jsonData !== null) {
            echo json_encode($this->jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }
        if ($this->body !== null) {
            echo $this->body;
        }
    }
}
