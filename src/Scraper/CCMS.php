<?php
namespace SHUTDOWN\Scraper;

class CCMS implements SourceInterface
{
    private string $url;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function fetch(): array
    {
        // Placeholder: page is dynamic; return empty to avoid brittle parsing
        return [];
    }
}
