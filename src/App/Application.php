<?php
namespace SHUTDOWN\App;

use SHUTDOWN\Scraper\LescoScraper;
use SHUTDOWN\Util\Analytics;
use SHUTDOWN\Util\Store;

class Application
{
    private Store $store;

    private LescoScraper $scraper;

    /**
     * @param callable(Store):LescoScraper|null $scraperFactory
     */
    public function __construct(string $storageDir, ?callable $scraperFactory = null)
    {
        $this->store = new Store($storageDir);
        $this->scraper = $scraperFactory ? $scraperFactory($this->store) : new LescoScraper($this->store);
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function scraper(): LescoScraper
    {
        return $this->scraper;
    }

    public function config(): array
    {
        return $this->store->readConfig();
    }

    public function updateConfig(array $config): void
    {
        $this->store->writeConfig($config);
    }

    public function analytics(): Analytics
    {
        $config = $this->config();
        $timezone = (string) ($config['timezone'] ?? 'Asia/Karachi');
        return new Analytics($timezone);
    }
}
