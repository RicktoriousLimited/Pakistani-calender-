<?php
namespace SHUTDOWN\Scraper;

interface SourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(): array;
}
