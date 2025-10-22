<?php
namespace SHUTDOWN\App\Http;

class HttpException extends \RuntimeException
{
    private int $status;

    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(int $status, string $message, array $payload = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->payload = $payload;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
