<?php
namespace SHUTDOWN\Scraper;

use SHUTDOWN\Util\PdfTextExtractor;

/**
 * Reads PDF bulletins that contain shutdown schedules formatted as
 * simple key/value lines (Area, Feeder, Start, End, Reason).
 */
class PdfBulletin implements SourceInterface
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
        $this->fallbacks = array_values(array_filter($fallbacks, static fn ($candidate) => is_string($candidate) && trim((string) $candidate) !== ''));
        $this->resolvedUrl = $url;
    }

    public function fetch(): array
    {
        $loader = $this->fetcher ?? [$this, 'download'];
        $pdfUrl = $this->resolvePdfUrl($loader);
        if ($pdfUrl === null) {
            return [];
        }

        $binary = $loader($pdfUrl);
        if (!is_string($binary) || $binary === '') {
            return [];
        }

        $text = PdfTextExtractor::extractText($binary);
        if ($text === '') {
            return [];
        }

        $this->resolvedUrl = $pdfUrl;

        return $this->parseText($text);
    }

    /**
     * @param callable(string): (string|null) $loader
     */
    private function resolvePdfUrl(callable $loader): ?string
    {
        if ($this->looksLikePdfUrl($this->url)) {
            return $this->url;
        }

        $candidates = [];
        if ($this->url !== '') {
            $candidates[] = $this->url;
        }
        foreach ($this->fallbacks as $fallback) {
            $candidates[] = $fallback;
        }
        foreach ($this->defaultDiscoveryPages() as $default) {
            $candidates[] = $default;
        }

        $visited = [];
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (isset($visited[$candidate])) {
                continue;
            }
            $visited[$candidate] = true;

            if ($this->looksLikePdfUrl($candidate)) {
                return $candidate;
            }

            $html = $loader($candidate);
            if (!is_string($html) || trim($html) === '') {
                continue;
            }

            if (strncmp($html, '%PDF', 4) === 0) {
                return $candidate;
            }

            $links = $this->extractPdfLinks($html, $candidate);
            if (!empty($links)) {
                return $links[0];
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function defaultDiscoveryPages(): array
    {
        return [
            'https://www.lesco.gov.pk/LoadSheddingShutdownSchedule',
            'https://www.lesco.gov.pk/LoadManagement',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractPdfLinks(string $html, string $baseUrl): array
    {
        $links = [];
        $previousLibxml = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        if (@$dom->loadHTML($html) !== false) {
            $xpath = new \DOMXPath($dom);
            foreach ($xpath->query('//*[@href]') as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $href = trim($node->getAttribute('href'));
                if ($href !== '' && $this->looksLikePdfUrl($href)) {
                    $links[] = $this->absolutizeUrl($href, $baseUrl);
                }
                foreach (['data-href', 'data-url'] as $attr) {
                    $candidate = trim($node->getAttribute($attr));
                    if ($candidate !== '' && $this->looksLikePdfUrl($candidate)) {
                        $links[] = $this->absolutizeUrl($candidate, $baseUrl);
                    }
                }
                $onclick = $node->getAttribute('onclick');
                if ($onclick !== '') {
                    foreach ($this->extractPdfUrlsFromString($onclick) as $candidate) {
                        $links[] = $this->absolutizeUrl($candidate, $baseUrl);
                    }
                }
            }

            foreach ($xpath->query('//*[@data-href or @data-url]') as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                foreach (['data-href', 'data-url'] as $attr) {
                    $candidate = trim($node->getAttribute($attr));
                    if ($candidate !== '' && $this->looksLikePdfUrl($candidate)) {
                        $links[] = $this->absolutizeUrl($candidate, $baseUrl);
                    }
                }
            }
        }
        if ($previousLibxml !== null) {
            libxml_use_internal_errors($previousLibxml);
        }

        if (empty($links)) {
            foreach ($this->extractPdfUrlsFromString($html) as $candidate) {
                $links[] = $this->absolutizeUrl($candidate, $baseUrl);
            }
        }

        $links = array_values(array_unique(array_filter($links)));

        usort($links, function (string $a, string $b): int {
            $scoreA = $this->scoreCandidate($a);
            $scoreB = $this->scoreCandidate($b);
            if ($scoreA === $scoreB) {
                return strcmp($b, $a);
            }
            return $scoreB <=> $scoreA;
        });

        return $links;
    }

    private function looksLikePdfUrl(string $url): bool
    {
        return (bool) preg_match('/\.pdf(?:$|[?#])/i', $url);
    }

    private function absolutizeUrl(string $href, string $baseUrl): string
    {
        $href = trim($href);
        if ($href === '') {
            return '';
        }
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+\-.]*://#', $href)) {
            return $href;
        }

        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return $href;
        }

        $fragment = '';
        if (str_contains($href, '#')) {
            [$href, $fragment] = explode('#', $href, 2);
            $fragment = '#' . $fragment;
        }

        $query = '';
        if (str_contains($href, '?')) {
            [$pathPart, $query] = explode('?', $href, 2);
            $href = $pathPart;
            $query = '?' . $query;
        }

        $path = $href;
        if ($path === '' || $path[0] === '/') {
            $normalized = $this->normalizePath($path === '' ? '/' : $path);
        } else {
            $basePath = $baseParts['path'] ?? '/';
            $baseDir = preg_replace('#/[^/]*$#', '/', $basePath) ?? '/';
            $normalized = $this->normalizePath($baseDir . $path);
        }

        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

        return $baseParts['scheme'] . '://' . $baseParts['host'] . $port . $normalized . $query . $fragment;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    /**
     * @return array<int, string>
     */
    private function extractPdfUrlsFromString(string $text): array
    {
        $found = [];
        if (preg_match_all("/https?:\/\/[^\"'\\s>]+\.pdf(?:\?[^\"'\\s<]*)?/i", $text, $matches)) {
            foreach ($matches[0] as $url) {
                $found[] = $url;
            }
        }
        if (preg_match_all("/([\\w\\-\\/.]+\\.pdf(?:\\?[^\"'\\s<]*)?)/i", $text, $matches)) {
            foreach ($matches[1] as $url) {
                if (!preg_match('#^[a-zA-Z][a-zA-Z0-9+\-.]*://#', $url)) {
                    $found[] = $url;
                }
            }
        }
        return $found;
    }

    private function scoreCandidate(string $url): int
    {
        if (preg_match('/(20\d{2})[-_\/]?(\d{2})[-_\/]?(\d{2})/', $url, $m)) {
            return (int) ($m[1] . $m[2] . $m[3]);
        }
        if (preg_match('/(\d{2})[-_\/]?(\d{2})[-_\/]?(20\d{2})/', $url, $m)) {
            return (int) ($m[3] . $m[2] . $m[1]);
        }
        return 0;
    }

    private function download(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Shutdown/1.0 (+PDF)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/pdf,application/octet-stream;q=0.9,*/*;q=0.8',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && is_string($body)) {
            return $body;
        }
        return null;
    }

    private function parseText(string $text): array
    {
        $lines = preg_split('/\r?\n+/', $text) ?: [];
        $items = [];
        $current = $this->emptyRecord();

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || $this->isHeader($line)) {
                continue;
            }

            if (preg_match('/^(Area|Feeder|Start|End|Reason)\s*:?-?\s*(.+)$/i', $line, $m)) {
                $field = strtolower($m[1]);
                $value = trim($m[2]);
                if ($field === 'area' && $this->isComplete($current)) {
                    $items[] = $this->finalize($current);
                    $current = $this->emptyRecord();
                }
                $this->assign($current, $field, $value);
                if ($this->isComplete($current) && $field !== 'reason') {
                    continue;
                }
                if ($this->isComplete($current)) {
                    $items[] = $this->finalize($current);
                    $current = $this->emptyRecord();
                }
                continue;
            }

            if ($current['area'] === '') {
                $current['area'] = $line;
                continue;
            }
            if ($current['feeder'] === '' && $this->looksLikeFeeder($line)) {
                $current['feeder'] = $line;
                continue;
            }
            if ($current['start'] === '' && $this->looksLikeDateTime($line)) {
                $current['start'] = $line;
                continue;
            }
            if ($current['end'] === '' && $this->looksLikeDateTime($line)) {
                $current['end'] = $line;
                continue;
            }
            $current['reason'] = trim($current['reason'] . ' ' . $line);
        }

        if ($this->isComplete($current)) {
            $items[] = $this->finalize($current);
        }

        return $items;
    }

    /**
     * @param array<string, string> $current
     */
    private function assign(array &$current, string $field, string $value): void
    {
        if ($field === 'area' || $field === 'feeder') {
            $current[$field] = $value;
            return;
        }
        if ($field === 'start' || $field === 'end') {
            $current[$field] = $value;
            return;
        }
        if ($field === 'reason') {
            $current['reason'] = $value;
        }
    }

    /**
     * @return array<string, string>
     */
    private function emptyRecord(): array
    {
        return [
            'area' => '',
            'feeder' => '',
            'start' => '',
            'end' => '',
            'reason' => '',
        ];
    }

    /**
     * @param array<string, string> $current
     */
    private function finalize(array $current): array
    {
        return [
            'utility' => 'LESCO',
            'area' => $current['area'],
            'feeder' => $current['feeder'],
            'start' => $current['start'],
            'end' => $current['end'],
            'type' => $this->inferType($current['reason']),
            'reason' => $current['reason'],
            'source' => 'pdf',
            'url' => $this->resolvedUrl !== '' ? $this->resolvedUrl : $this->url,
            'confidence' => 0.75,
        ];
    }

    /**
     * @param array<string, string> $current
     */
    private function isComplete(array $current): bool
    {
        if ($current['area'] === '' && $current['feeder'] === '') {
            return false;
        }
        if ($current['start'] === '') {
            return false;
        }
        return true;
    }

    private function isHeader(string $line): bool
    {
        $lower = strtolower($line);
        return str_contains($lower, 'area') && str_contains($lower, 'feeder') && str_contains($lower, 'start');
    }

    private function looksLikeDateTime(string $value): bool
    {
        return (bool) preg_match('/\d{4}[-\/]\d{2}[-\/]\d{2}/', $value)
            || (bool) preg_match('/\d{1,2}[-\/]\d{1,2}[-\/]\d{2,4}/', $value)
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
        return strlen($value) <= 40;
    }

    private function inferType(string $reason): string
    {
        $lower = strtolower($reason);
        if (str_contains($lower, 'maint')) {
            return 'maintenance';
        }
        if (str_contains($lower, 'emerg')) {
            return 'forced';
        }
        return 'scheduled';
    }
}
