<?php
namespace SHUTDOWN\Scraper;

class Official implements SourceInterface
{
    private string $url;

    /** @var callable|null */
    private $fetcher;

    /** @var array<int, string> */
    private array $fallbacks;

    private string $resolvedUrl;

    public function __construct(string $url, ?callable $fetcher = null, array $fallbacks = [])
    {
        $this->url = $url;
        $this->fetcher = $fetcher;
        $this->fallbacks = array_values(array_filter(array_map(static function ($candidate): string {
            return is_string($candidate) ? trim($candidate) : '';
        }, $fallbacks), static fn (string $candidate): bool => $candidate !== ''));
        $this->resolvedUrl = $url;
    }

    private function fetchHtml(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Shutdown/1.0 (+HTML)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && is_string($html) && $html !== '') {
            return $html;
        }
        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(): array
    {
        $loader = $this->fetcher ?? [$this, 'fetchHtml'];
        $items = [];

        foreach ($this->candidateUrls() as $candidate) {
            $html = $this->callLoader($loader, $candidate);
            if (!is_string($html) || trim($html) === '') {
                continue;
            }
            $items = $this->parseHtml($html, $candidate);
            if (!empty($items)) {
                $this->resolvedUrl = $candidate;
                break;
            }
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function candidateUrls(): array
    {
        $candidates = [];
        if (trim($this->url) !== '') {
            $candidates[] = trim($this->url);
        }
        foreach ($this->fallbacks as $fallback) {
            $candidates[] = $fallback;
        }
        foreach ($this->defaultDiscoveryPages() as $default) {
            $candidates[] = $default;
        }

        $unique = [];
        $result = [];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            if (isset($unique[$candidate])) {
                continue;
            }
            $unique[$candidate] = true;
            $result[] = $candidate;
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function defaultDiscoveryPages(): array
    {
        return [
            'https://www.lesco.gov.pk/LoadSheddingShutdownSchedule',
            'https://www.lesco.gov.pk/LoadManagement',
            'https://www.lesco.gov.pk/~/LoadManagement',
            'http://www.lesco.gov.pk/Modules/ShutdownSchedule/Select_Customer.asp',
        ];
    }

    private function callLoader(callable $loader, string $url): mixed
    {
        try {
            return $loader($url);
        } catch (\ArgumentCountError $e) {
            return $loader();
        }
    }

    private function parseHtml(string $html, string $pageUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        if (@$dom->loadHTML($html) === false) {
            if ($previous !== null) {
                libxml_use_internal_errors($previous);
            }
            return [];
        }
        if ($previous !== null) {
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($dom);
        $tables = $xpath->query('//table');
        if ($tables === false) {
            return [];
        }

        $items = [];
        foreach ($tables as $table) {
            if (!$table instanceof \DOMElement) {
                continue;
            }
            $rows = $this->parseTable($table, $pageUrl);
            if (!empty($rows)) {
                $items = array_merge($items, $rows);
                if (!empty($items)) {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseTable(\DOMElement $table, string $pageUrl): array
    {
        $header = $this->extractHeader($table);
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = $this->extractCells($tr);
            if (empty($cells)) {
                continue;
            }
            if ($this->isHeaderRow($cells)) {
                continue;
            }

            $item = $this->buildItemFromRow($cells, $header, $pageUrl);
            if ($item !== null) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function extractHeader(\DOMElement $table): array
    {
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && strtolower($cell->tagName) === 'th') {
                    $cells[] = $this->cleanText($cell->textContent);
                }
            }
            if (!empty($cells)) {
                return $cells;
            }
        }

        $firstRow = $table->getElementsByTagName('tr')->item(0);
        if ($firstRow instanceof \DOMElement) {
            $cells = $this->extractCells($firstRow);
            if ($this->looksLikeHeaderValues($cells)) {
                return $cells;
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function extractCells(\DOMElement $tr): array
    {
        $cells = [];
        foreach ($tr->childNodes as $cell) {
            if (!$cell instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($cell->tagName);
            if ($tag !== 'td' && $tag !== 'th') {
                continue;
            }
            $text = $this->cleanText($cell->textContent);
            if ($text === '') {
                $text = trim($cell->getAttribute('data-label'));
            }
            $cells[] = $text;
        }
        return $cells;
    }

    private function cleanText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $text) ?? '';
        return trim(html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5));
    }

    private function isHeaderRow(array $cells): bool
    {
        if (empty($cells)) {
            return true;
        }
        return $this->looksLikeHeaderValues($cells);
    }

    private function looksLikeHeaderValues(array $cells): bool
    {
        $keywords = ['area', 'feeder', 'division', 'sub', 'date', 'time', 'reason', 'remarks', 'shutdown'];
        $nonNumeric = 0;
        foreach ($cells as $cell) {
            if ($cell === '') {
                continue;
            }
            if (preg_match('/\d{2,}/', $cell)) {
                return false;
            }
            $lower = strtolower($cell);
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $nonNumeric++;
                    continue 2;
                }
            }
            if (preg_match('/^[A-Z\s]+$/', strtoupper($cell))) {
                $nonNumeric++;
                continue;
            }
            return false;
        }
        return $nonNumeric >= 2;
    }

    private function buildItemFromRow(array $cells, array $header, string $pageUrl): ?array
    {
        $areaParts = [];
        $feeder = '';
        $reasonParts = [];
        $start = '';
        $end = '';
        $date = '';
        $startTime = '';
        $endTime = '';

        if (!empty($header)) {
            foreach ($cells as $idx => $value) {
                $label = strtolower($header[$idx] ?? '');
                $normalizedLabel = preg_replace('/[^a-z]+/', ' ', $label) ?? '';
                if ($value === '') {
                    continue;
                }
                if (str_contains($normalizedLabel, 'feeder')) {
                    $feeder = $value;
                    continue;
                }
                if (str_contains($normalizedLabel, 'sub division') || str_contains($normalizedLabel, 'subdivision') || str_contains($normalizedLabel, 'area') || str_contains($normalizedLabel, 'locality') || str_contains($normalizedLabel, 'town')) {
                    $areaParts[] = $value;
                    continue;
                }
                if (str_contains($normalizedLabel, 'division') || str_contains($normalizedLabel, 'circle')) {
                    if (!$this->looksLikeFeeder($value)) {
                        $areaParts[] = $value;
                    }
                    continue;
                }
                if (str_contains($normalizedLabel, 'shutdown') || str_contains($normalizedLabel, 'date')) {
                    if ($date === '') {
                        $date = $value;
                    }
                    continue;
                }
                if (str_contains($normalizedLabel, 'start') || str_contains($normalizedLabel, 'from')) {
                    if ($startTime === '') {
                        $startTime = $value;
                    }
                    continue;
                }
                if (str_contains($normalizedLabel, 'end') || str_contains($normalizedLabel, 'to')) {
                    if ($endTime === '') {
                        $endTime = $value;
                    }
                    continue;
                }
                if (str_contains($normalizedLabel, 'reason') || str_contains($normalizedLabel, 'remarks') || str_contains($normalizedLabel, 'purpose') || str_contains($normalizedLabel, 'work')) {
                    $reasonParts[] = $value;
                }
            }
        }

        if ($feeder === '' && isset($cells[1])) {
            $feeder = $cells[1];
        }
        if (empty($areaParts) && isset($cells[0])) {
            $areaParts[] = $cells[0];
        }
        if ($start === '' && isset($cells[2]) && $this->looksLikeDateTime($cells[2])) {
            $start = $cells[2];
        }
        if ($end === '' && isset($cells[3]) && $this->looksLikeDateTime($cells[3])) {
            $end = $cells[3];
        }
        if (empty($reasonParts) && isset($cells[4])) {
            $reasonParts[] = $cells[4];
        }

        if ($date !== '' && $startTime !== '') {
            $start = $this->combineDateTime($date, $startTime);
        } elseif ($start === '' && $startTime !== '') {
            $start = $startTime;
        }
        if ($date !== '' && $endTime !== '') {
            $end = $this->combineDateTime($date, $endTime);
        } elseif ($end === '' && $endTime !== '') {
            $end = $endTime;
        }

        $area = $this->combineAreaParts($areaParts);
        $reason = trim(implode(' ', array_filter($reasonParts)));

        if ($area === '' && $feeder === '') {
            return null;
        }
        if ($start === '') {
            return null;
        }

        return [
            'utility' => 'LESCO',
            'area' => $area,
            'feeder' => $feeder,
            'start' => $start,
            'end' => $end,
            'type' => 'scheduled',
            'reason' => $reason,
            'source' => 'official',
            'url' => $pageUrl,
            'confidence' => 0.9,
        ];
    }

    /**
     * @param array<int, string> $parts
     */
    private function combineAreaParts(array $parts): string
    {
        $unique = [];
        foreach ($parts as $part) {
            $clean = trim($part);
            if ($clean === '') {
                continue;
            }
            if (isset($unique[$clean])) {
                continue;
            }
            $unique[$clean] = true;
        }

        return implode(' | ', array_keys($unique));
    }

    private function combineDateTime(string $date, string $time): string
    {
        $date = $this->normalizeDate($date);
        $time = $this->normalizeTime($time);
        if ($date === '') {
            return $time;
        }
        if ($time === '') {
            return $date;
        }

        $timestamp = strtotime($date . ' ' . $time);
        if ($timestamp !== false) {
            return date('Y-m-d H:i', $timestamp);
        }

        return trim($date . ' ' . $time);
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{2})[\/-](\d{2})[\/-](\d{4})$/', $value, $m)) {
            return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^(\d{4})[\/-](\d{2})[\/-](\d{2})$/', $value, $m)) {
            return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        return $value;
    }

    private function normalizeTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $timestamp = strtotime('1970-01-01 ' . $value);
        if ($timestamp !== false) {
            return date('H:i', $timestamp);
        }
        return $value;
    }

    private function looksLikeDateTime(string $value): bool
    {
        return (bool) preg_match('/\d{4}[\/-]\d{2}[\/-]\d{2}/', $value)
            || (bool) preg_match('/\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}/', $value)
            || (bool) preg_match('/\d{1,2}:\d{2}/', $value);
    }

    private function looksLikeFeeder(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        if (preg_match('/\bF[\-\s]?\d+/i', $value)) {
            return true;
        }
        if (preg_match('/[A-Za-z]/', $value) && preg_match('/\d/', $value)) {
            return true;
        }
        if (preg_match('/\d{3,}/', $value)) {
            return true;
        }
        return false;
    }
}
