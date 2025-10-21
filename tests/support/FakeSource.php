<?php
declare(strict_types=1);

namespace Tests\Support;

use SHUTDOWN\Scraper\SourceInterface;

final class FakeSource implements SourceInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function fetch(): array
    {
        return $this->items;
    }
}

final class ThrowingSource implements SourceInterface
{
    public function fetch(): array
    {
        throw new \RuntimeException('boom');
    }
}
