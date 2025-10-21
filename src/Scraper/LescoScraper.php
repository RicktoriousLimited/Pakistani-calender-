<?php
namespace SHUTDOWN\Scraper;

use SHUTDOWN\Parser\ManualCsv;
use SHUTDOWN\Util\Merge;
use SHUTDOWN\Util\Store;
use SHUTDOWN\Scraper\PdfBulletin;

class LescoScraper
{
    private Store $store;

    /** @var array<string, callable> */
    private array $factories;

    /** @var array<string, mixed> */
    private array $lastReport = [];

    /**
     * @param array<string, callable> $factories
     */
    public function __construct(Store $store, array $factories = [])
    {
        $this->store = $store;
        $this->factories = $factories;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(int $sampleSize = 5): array
    {
        $result = $this->collect($sampleSize);
        $this->lastReport = $result['report'];
        return $result['items'];
    }

    public function lastReport(): array
    {
        return $this->lastReport;
    }

    public function probe(int $sampleSize = 5): array
    {
        $result = $this->collect($sampleSize);
        $this->lastReport = $result['report'];
        return [
            'ok' => true,
            'generatedAt' => $result['report']['generatedAt'],
            'total' => $result['report']['total'],
            'sources' => $result['report']['sources'],
            'sample' => array_slice($result['items'], 0, $sampleSize),
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, report: array<string, mixed>}
     */
    private function collect(int $sampleSize): array
    {
        $config = $this->store->readConfig();
        $sourcesConfig = $config['sources'] ?? [];

        $collections = [];
        $reportSources = [];

        foreach ($sourcesConfig as $name => $cfg) {
            if (empty($cfg['enabled'])) {
                continue;
            }
            if ($name === 'manual') {
                continue;
            }
            $source = $this->makeSource($name, $cfg);
            if (!$source) {
                continue;
            }
            try {
                $data = $source->fetch();
                $reportSources[$name] = [
                    'ok' => true,
                    'count' => count($data),
                    'sample' => array_slice($data, 0, $sampleSize),
                ];
                $collections[] = $data;
            } catch (\Throwable $e) {
                $reportSources[$name] = [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (!empty($sourcesConfig['manual']['enabled'])) {
            $manual = (new ManualCsv($this->store->manualPath()))->read();
            $reportSources['manual'] = [
                'ok' => true,
                'count' => count($manual),
                'sample' => array_slice($manual, 0, $sampleSize),
            ];
            $collections[] = $manual;
        }

        $merged = Merge::merge($collections);
        $enriched = array_map(fn ($it) => $this->store->enrichItem($it), $merged);

        $report = [
            'generatedAt' => gmdate('c'),
            'total' => count($enriched),
            'sources' => $reportSources,
        ];

        return ['items' => $enriched, 'report' => $report];
    }

    private function makeSource(string $name, array $cfg): ?SourceInterface
    {
        if (isset($this->factories[$name])) {
            $candidate = ($this->factories[$name])($cfg);
            if ($candidate instanceof SourceInterface) {
                return $candidate;
            }
            throw new \InvalidArgumentException('Factory for ' . $name . ' must return SourceInterface');
        }

        $url = (string)($cfg['url'] ?? '');
        return match ($name) {
            'official' => new Official($url),
            'facebook' => new FacebookPR($url),
            'ccms' => new CCMS($url),
            'pdf' => new PdfBulletin($url),
            default => null,
        };
    }
}
