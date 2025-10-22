<?php
namespace SHUTDOWN\Scraper;

use SHUTDOWN\Util\PdfTextExtractor;

/**
 * Reads PDF bulletins that contain shutdown schedules formatted as
 * simple key/value lines (Area, Feeder, Start, End, Reason).
 */
class PdfBulletin implements SourceInterface
{
    private const MAX_DISCOVERY_DEPTH = 2;
    private const MAX_FOLLOW_LINKS_PER_PAGE = 12;

    private string $url;

    /** @var callable|null */
    private $fetcher;

    /** @var array<int, string> */
    private array $fallbacks;

    /** @var callable */
    private $extractor;

    private string $resolvedUrl;

    public function __construct(string $url, ?callable $fetcher = null, array $fallbacks = [], ?callable $extractor = null)
    {
        $this->url = $url;
        $this->fetcher = $fetcher;
        $this->fallbacks = array_values(array_filter($fallbacks, static fn ($candidate) => is_string($candidate) && trim((string) $candidate) !== ''));
        $this->extractor = $extractor ?? [PdfTextExtractor::class, 'extractText'];
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

        $extractor = $this->extractor;
        $text = $extractor($binary);
        if (!is_string($text) || $text === '') {
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

            $nested = $this->followLinksForPdf($loader, $html, $candidate, $visited, self::MAX_DISCOVERY_DEPTH);
            if ($nested !== null) {
                return $nested;
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
            'https://www.lesco.gov.pk/shutdownschedule',
            'https://www.lesco.gov.pk/TBR',
            'https://www.lesco.gov.pk/tbr',
            'https://www.lesco.gov.pk/LoadSheddingShutdownSchedule',
            'https://www.lesco.gov.pk/LoadManagement',
            'https://www.lesco.gov.pk/~/LoadManagement',
            'https://www.lesco.gov.pk/Notice_files/SDLS/',
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
            $attributeGroups = [
                ['selector' => '//*[@href]', 'attributes' => ['href']],
                ['selector' => '//*[@src]', 'attributes' => ['src']],
                ['selector' => '//object[@data]', 'attributes' => ['data']],
                [
                    'selector' => '//*[@data-href or @data-url or @data-src or @data-file or @data-download or @data-latest or @data-latest-pdf]',
                    'attributes' => ['data-href', 'data-url', 'data-src', 'data-file', 'data-download', 'data-latest', 'data-latest-pdf'],
                ],
            ];
            foreach ($attributeGroups as $group) {
                $nodes = $xpath->query($group['selector']);
                if (!$nodes) {
                    continue;
                }
                foreach ($nodes as $node) {
                    if (!$node instanceof \DOMElement) {
                        continue;
                    }
                    foreach ($group['attributes'] as $attr) {
                        if (!$node->hasAttribute($attr)) {
                            continue;
                        }
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
            }

            foreach ($xpath->query('//meta[@http-equiv]') as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                if (strtolower($node->getAttribute('http-equiv')) !== 'refresh') {
                    continue;
                }
                $content = $node->getAttribute('content');
                foreach ($this->extractPdfUrlsFromMetaRefresh($content) as $candidate) {
                    $links[] = $this->absolutizeUrl($candidate, $baseUrl);
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

    private function followLinksForPdf(callable $loader, string $html, string $baseUrl, array &$visited, int $depth): ?string
    {
        if ($depth <= 0) {
            return null;
        }

        $followLinks = $this->extractFollowableLinks($html, $baseUrl);
        if (empty($followLinks)) {
            return null;
        }

        foreach ($followLinks as $link) {
            if (isset($visited[$link])) {
                continue;
            }
            $visited[$link] = true;

            if ($this->looksLikePdfUrl($link)) {
                return $link;
            }

            $body = $loader($link);
            if (!is_string($body) || trim($body) === '') {
                continue;
            }

            if (strncmp($body, '%PDF', 4) === 0) {
                return $link;
            }

            $pdfLinks = $this->extractPdfLinks($body, $link);
            if (!empty($pdfLinks)) {
                return $pdfLinks[0];
            }

            $nested = $this->followLinksForPdf($loader, $body, $link, $visited, $depth - 1);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractFollowableLinks(string $html, string $baseUrl): array
    {
        $links = [];
        $previousLibxml = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        if (@$dom->loadHTML($html) !== false) {
            $xpath = new \DOMXPath($dom);
            $nodes = $xpath->query('//a[@href]');
            if ($nodes) {
                foreach ($nodes as $node) {
                    if (!$node instanceof \DOMElement) {
                        continue;
                    }
                    $href = trim($node->getAttribute('href'));
                    if ($href === '' || $href[0] === '#') {
                        continue;
                    }
                    if (preg_match('/^(?:javascript|mailto|tel):/i', $href)) {
                        continue;
                    }
                    $absolute = $this->absolutizeUrl($href, $baseUrl);
                    if ($absolute === '') {
                        continue;
                    }
                    if (!$this->shouldFollowLink($absolute, $baseUrl)) {
                        continue;
                    }
                    $links[] = $absolute;
                }
            }
        }
        if ($previousLibxml !== null) {
            libxml_use_internal_errors($previousLibxml);
        }

        if (empty($links)) {
            return [];
        }

        $unique = array_values(array_unique($links));

        if (count($unique) > self::MAX_FOLLOW_LINKS_PER_PAGE) {
            $unique = array_slice($unique, 0, self::MAX_FOLLOW_LINKS_PER_PAGE);
        }

        return $unique;
    }

    private function shouldFollowLink(string $candidate, string $baseUrl): bool
    {
        if ($this->looksLikePdfUrl($candidate)) {
            return true;
        }

        $candidateParts = parse_url($candidate);
        if (!is_array($candidateParts) || empty($candidateParts['scheme'])) {
            return false;
        }

        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts) || empty($baseParts['host'])) {
            return true;
        }

        if (empty($candidateParts['host'])) {
            return true;
        }

        return strtolower((string) $candidateParts['host']) === strtolower((string) $baseParts['host']);
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

        if (function_exists('html_entity_decode')) {
            $flags = ENT_QUOTES;
            if (defined('ENT_HTML5')) {
                $flags |= ENT_HTML5;
            }
            $href = html_entity_decode($href, $flags, 'UTF-8');
        }

        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+\-.]*://#', $href)) {
            return $this->encodeAbsoluteUrl($href);
        }

        $baseParts = parse_url($baseUrl);
        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return $this->encodeAbsoluteUrl($href);
        }

        if (str_starts_with($href, '//')) {
            return $this->encodeAbsoluteUrl(($baseParts['scheme'] ?? 'https') . ':' . $href);
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
        $encodedPath = $this->encodePath($normalized);

        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

        return $baseParts['scheme'] . '://' . $baseParts['host'] . $port . $encodedPath . $this->encodeQueryFragment($query) . $this->encodeQueryFragment($fragment);
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

    private function encodePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $leadingSlash = $path[0] === '/';
        $segments = explode('/', $path);
        foreach ($segments as $index => $segment) {
            if ($segment === '' && $leadingSlash && $index === 0) {
                continue;
            }
            if ($segment === '') {
                $segments[$index] = '';
                continue;
            }
            $segments[$index] = rawurlencode(rawurldecode($segment));
        }

        $encoded = implode('/', $segments);
        if ($leadingSlash && !str_starts_with($encoded, '/')) {
            $encoded = '/' . $encoded;
        }

        return $encoded;
    }

    private function encodeQueryFragment(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $prefix = $value[0];
        if ($prefix !== '?' && $prefix !== '#') {
            return str_replace(' ', '%20', $value);
        }

        $body = substr($value, 1);
        if ($body === false) {
            $body = '';
        }

        $body = preg_replace('/\s+/', '%20', $body) ?? $body;

        return $prefix . $body;
    }

    private function encodeAbsoluteUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return str_replace(' ', '%20', $url);
        }

        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $userInfo = '';
        if (isset($parts['user'])) {
            $userInfo = rawurlencode($parts['user']);
            if (isset($parts['pass'])) {
                $userInfo .= ':' . rawurlencode($parts['pass']);
            }
            $userInfo .= '@';
        }

        $path = $this->encodePath($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . preg_replace('/\s+/', '%20', $parts['query']) : '';
        $fragment = isset($parts['fragment']) ? '#' . preg_replace('/\s+/', '%20', $parts['fragment']) : '';

        return $scheme . '://' . $userInfo . $host . $port . $path . $query . $fragment;
    }

    /**
     * @return array<int, string>
     */
    private function extractPdfUrlsFromMetaRefresh(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $matches = [];
        if (!preg_match_all('/url\s*=\s*([^;]+)/i', $content, $matches)) {
            return [];
        }

        $urls = [];
        foreach ($matches[1] as $raw) {
            $candidate = trim($raw, "\"' \t\r\n");
            if ($candidate === '') {
                continue;
            }
            if ($this->looksLikePdfUrl($candidate)) {
                $urls[] = $candidate;
            }
        }

        return $urls;
    }

    /**
     * @return array<int, string>
     */
    private function extractPdfUrlsFromString(string $text): array
    {
        $found = [];
        if (preg_match_all('/https?:\/\/[^\s\"\'<>]+\.pdf(?:\?[^\s\"\'<>]*)?/i', $text, $matches)) {
            foreach ($matches[0] as $url) {
                $candidate = $this->cleanExtractedUrl((string) $url);
                if ($candidate !== '' && $this->looksLikePdfUrl($candidate)) {
                    $found[] = $candidate;
                }
            }
        }
        $patterns = [
            ['/["\']\s*([^"\']+\.pdf(?:\?[^"\']*)?)\s*["\']/i', 1],
            ['/(?:^|["\'\s(>])((?:https?:\/\/|\/|\.{1,2}\/|~\/)?[^"\'<>]*?\.pdf(?:\?[^"\'<>\s]*)?)/i', 1],
        ];
        foreach ($patterns as [$pattern, $group]) {
            if (!preg_match_all($pattern, $text, $matches)) {
                continue;
            }
            foreach ($matches[$group] as $raw) {
                $candidate = $this->cleanExtractedUrl((string) $raw);
                if ($candidate === '') {
                    continue;
                }
                if (!$this->looksLikePdfUrl($candidate)) {
                    continue;
                }
                $found[] = $candidate;
            }
        }

        $found = array_values(array_unique($found));

        return $found;
    }

    private function cleanExtractedUrl(string $candidate): string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return '';
        }

        $candidate = trim($candidate, "\"'`");
        $candidate = trim($candidate);
        $candidate = ltrim($candidate, "\"'(<[{ ");
        $candidate = rtrim($candidate, "\"')>]}.;:, ");

        return trim($candidate);
    }

    private function scoreCandidate(string $url): int
    {
        if (preg_match('/(20\d{2})[-_\/. ]?(\d{1,2})[-_\/. ]?(\d{1,2})/', $url, $m)) {
            return (int) sprintf('%04d%02d%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/(\d{1,2})[-_\/. ]?(\d{1,2})[-_\/. ]?(20\d{2})/', $url, $m)) {
            return (int) sprintf('%04d%02d%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/(\d{1,2})[-_\/. ]?(\d{1,2})[-_\/. ]?(\d{2})/', $url, $m)) {
            $year = (int) $m[3];
            $year += $year >= 70 ? 1900 : 2000;
            return (int) sprintf('%04d%02d%02d', $year, (int) $m[2], (int) $m[1]);
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
        $tabular = $this->parseTabular($lines);
        if (!empty($tabular)) {
            return $tabular;
        }

        return $this->parseKeyValue($lines);
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    private function parseKeyValue(array $lines): array
    {
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
     * @param array<int, string> $lines
     * @return array<int, array<string, mixed>>
     */
    private function parseTabular(array $lines): array
    {
        $tokens = [];
        $window = [];
        $tableStarted = false;

        foreach ($lines as $rawLine) {
            $line = $this->cleanLine($rawLine);
            if ($line === '') {
                continue;
            }

            $lower = strtolower($line);
            $window[] = $lower;
            if (count($window) > 6) {
                array_shift($window);
            }

            if (!$tableStarted) {
                $joined = implode(' ', $window);
                $normalizedJoined = strtolower($joined);
                $normalizedJoined = preg_replace('/\s+/', ' ', $normalizedJoined) ?? $normalizedJoined;
                $compact = str_replace(' ', '', $normalizedJoined);

                if (str_contains($compact, 'shutdown') && str_contains($compact, 'date')) {
                    $tableStarted = true;
                    $window = [];
                    continue;
                }
                if (
                    (str_contains($compact, 'starttime') && str_contains($compact, 'endtime'))
                    || str_contains($compact, 'remarks')
                    || str_contains($compact, 'natureofwork')
                    || str_contains($compact, 'description')
                ) {
                    $tableStarted = true;
                    $window = [];
                    continue;
                }
                continue;
            }

            if ($this->isTableHeaderToken($line)) {
                continue;
            }

            $tokens[] = $line;
        }

        if (empty($tokens)) {
            return [];
        }

        $items = [];
        $current = $this->emptyTableRow();

        foreach ($tokens as $token) {
            if ($this->isRowStartToken($token)) {
                if ($this->tableRowComplete($current)) {
                    $item = $this->finalizeTableRow($current);
                    if ($item !== null) {
                        $items[] = $item;
                    }
                }
                $current = $this->emptyTableRow();
                continue;
            }

            if ($current['date'] === '' && $this->looksLikeDateValue($token)) {
                $current['date'] = $token;
                continue;
            }

            if ($current['date'] !== '' && $this->assignTimeToken($current, $token)) {
                continue;
            }

            if ($current['date'] !== '' && ($current['start'] !== '' || $current['end'] !== '')) {
                if ($this->isTimeBridge($token)) {
                    continue;
                }
                $current['remarks'][] = $token;
                continue;
            }

            $current['prefix'][] = $token;
        }

        if ($this->tableRowComplete($current)) {
            $item = $this->finalizeTableRow($current);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function cleanLine(string $line): string
    {
        $line = trim($line);
        if ($line === '') {
            return '';
        }
        $line = preg_replace('/\s+/u', ' ', $line) ?? $line;
        return trim($line);
    }

    private function isTableHeaderToken(string $line): bool
    {
        $normalized = strtolower($line);
        $normalized = str_replace('shut down', 'shutdown', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);
        if ($normalized === '') {
            return true;
        }
        $headers = [
            'serial',
            'serial no',
            'sr',
            'sr.',
            'sr no',
            'sr. no',
            'sr.no',
            's.no',
            'no',
            'no.',
            'division',
            'circle',
            'sub division',
            'sub-division',
            'subdivision',
            'area',
            'feeder',
            'feeder name',
            'feeder code',
            'name of feeder',
            'feeder name & code',
            'feeder name&code',
            'feeder name/code',
            'shutdown date',
            'shut down date',
            'shutdown from',
            'shutdown to',
            'shutdown time from',
            'shutdown time to',
            'time from',
            'time to',
            'date',
            'start time',
            'start',
            'end time',
            'end',
            'remarks',
            'reason',
            'nature of work',
            'type of work',
            'description',
            'grid station',
            'grid',
        ];
        return in_array($normalized, $headers, true);
    }

    private function isRowStartToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $normalized = str_replace(' ', '', $token);
        if (!preg_match('/^\d+[.)]?$/', $normalized)) {
            return false;
        }
        if ($this->looksLikeTimeValue($token)) {
            return false;
        }
        if ($this->looksLikeDateValue($token)) {
            return false;
        }
        return true;
    }

    private function looksLikeDateValue(string $value): bool
    {
        return (bool) preg_match('/\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}/', $value)
            || (bool) preg_match('/\d{4}[-\/.]\d{2}[-\/.]\d{2}/', $value);
    }

    /**
     * @param array{prefix: array<int, string>, date: string, start: string, end: string, remarks: array<int, string>} $row
     */
    private function assignTimeToken(array &$row, string $token): bool
    {
        $times = $this->extractTimes($token);
        if (count($times) >= 2) {
            if ($row['start'] === '') {
                $row['start'] = $times[0];
            }
            if ($row['end'] === '') {
                $row['end'] = $times[1];
            }
            return true;
        }

        if ($row['start'] === '' && $this->looksLikeTimeValue($token)) {
            $row['start'] = $token;
            return true;
        }

        if ($row['start'] !== '' && $row['end'] === '' && $this->isTimeSuffix($token)) {
            $row['start'] .= ' ' . $this->normalizeTimeSuffix($token);
            return true;
        }

        if ($row['start'] !== '' && $row['end'] === '' && $this->looksLikeTimeValue($token)) {
            $row['end'] = $token;
            return true;
        }

        if ($row['end'] !== '' && $this->isTimeSuffix($token)) {
            $row['end'] .= ' ' . $this->normalizeTimeSuffix($token);
            return true;
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function extractTimes(string $value): array
    {
        if (!preg_match_all('/\d{1,2}:\d{2}\s*(?:[APap]\.?M\.?)?/', $value, $matches)) {
            return [];
        }
        return array_map(static function (string $time): string {
            $time = preg_replace('/\s+/', ' ', $time) ?? $time;
            $time = str_ireplace(['a.m.', 'p.m.'], ['AM', 'PM'], $time);
            $time = str_ireplace([' am', ' pm'], [' AM', ' PM'], $time);
            return trim($time);
        }, $matches[0]);
    }

    private function looksLikeTimeValue(string $value): bool
    {
        if (preg_match('/\d{1,2}:\d{2}/', $value)) {
            return true;
        }
        if (preg_match('/^\d{3,4}$/', preg_replace('/\D/', '', $value) ?? '')) {
            return true;
        }
        return false;
    }

    private function isTimeSuffix(string $token): bool
    {
        $normalized = strtolower(str_replace('.', '', $token));
        return in_array($normalized, ['am', 'pm'], true);
    }

    private function normalizeTimeSuffix(string $token): string
    {
        $normalized = strtolower(str_replace('.', '', $token));
        return strtoupper($normalized);
    }

    private function isTimeBridge(string $token): bool
    {
        $normalized = strtolower(str_replace('.', '', $token));
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;
        if ($normalized === '') {
            return false;
        }
        return in_array($normalized, ['to', 'upto', 'till', 'until', '-', '—', '–', 'hrs', 'hours', 'hr'], true);
    }

    /**
     * @return array{prefix: array<int, string>, date: string, start: string, end: string, remarks: array<int, string>}
     */
    private function emptyTableRow(): array
    {
        return [
            'prefix' => [],
            'date' => '',
            'start' => '',
            'end' => '',
            'remarks' => [],
        ];
    }

    /**
     * @param array{prefix: array<int, string>, date: string, start: string, end: string, remarks: array<int, string>} $row
     */
    private function tableRowComplete(array $row): bool
    {
        if (empty($row['prefix'])) {
            return false;
        }
        if ($row['date'] === '') {
            return false;
        }
        if ($row['start'] === '' && $row['end'] === '') {
            return false;
        }
        return true;
    }

    /**
     * @param array{prefix: array<int, string>, date: string, start: string, end: string, remarks: array<int, string>} $row
     */
    private function finalizeTableRow(array $row): ?array
    {
        [$area, $feeder] = $this->splitAreaFeeder($row['prefix']);
        if ($area === '' && $feeder === '') {
            return null;
        }

        $record = $this->emptyRecord();
        $record['area'] = $area;
        $record['feeder'] = $feeder;
        $record['start'] = $this->combineDateTime($row['date'], $row['start']);
        $record['end'] = $this->combineDateTime($row['date'], $row['end'] !== '' ? $row['end'] : $row['start']);
        $record['reason'] = trim(implode(' ', $row['remarks']));

        if (!$this->isComplete($record)) {
            return null;
        }

        return $this->finalize($record);
    }

    /**
     * @param array<int, string> $tokens
     * @return array{0: string, 1: string}
     */
    private function splitAreaFeeder(array $tokens): array
    {
        $tokens = array_values(array_filter(array_map('trim', $tokens), static fn ($token) => $token !== ''));
        if (empty($tokens)) {
            return ['', ''];
        }

        $feederStart = null;
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            if ($this->looksLikeFeederToken($tokens[$i])) {
                $feederStart = $i;
            } elseif ($feederStart !== null) {
                break;
            }
        }

        if ($feederStart === null) {
            return [$this->formatAreaTokens($tokens), ''];
        }

        $areaTokens = array_slice($tokens, 0, $feederStart);
        $feederTokens = array_slice($tokens, $feederStart);

        if (empty($areaTokens)) {
            $areaTokens = $feederTokens;
            $feederTokens = [];
        }

        return [$this->formatAreaTokens($areaTokens), $this->formatFeederTokens($feederTokens)];
    }

    private function looksLikeFeederToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }
        if (preg_match('/\d/', $token)) {
            return true;
        }
        if (preg_match('/\b(11\s*-?kv|gss|grid|feeder)\b/i', $token)) {
            return true;
        }
        if (preg_match('/^f[\s\-]?\d+/i', $token)) {
            return true;
        }
        return false;
    }

    /**
     * @param array<int, string> $tokens
     */
    private function formatAreaTokens(array $tokens): string
    {
        if (empty($tokens)) {
            return '';
        }

        $parts = [];
        foreach ($tokens as $token) {
            $token = preg_replace('/\b(circle|division|sub\s*-?division|grid\s*station)\b/i', '', $token) ?? $token;
            $token = preg_replace('/\s+/', ' ', $token) ?? $token;
            $token = trim($token);
            if ($token !== '') {
                $parts[] = $token;
            }
        }
        $parts = array_values(array_unique($parts));
        if (empty($parts)) {
            return '';
        }
        return implode(' | ', $parts);
    }

    /**
     * @param array<int, string> $tokens
     */
    private function formatFeederTokens(array $tokens): string
    {
        if (empty($tokens)) {
            return '';
        }
        $joined = preg_replace('/\s+/', ' ', implode(' ', $tokens)) ?? '';
        $joined = preg_replace('/\b(feeder|name|code)\b/i', '', $joined) ?? $joined;
        return trim($joined);
    }

    private function combineDateTime(string $date, string $time): string
    {
        $date = $this->normalizeDateValue($date);
        $time = $this->normalizeTimeValue($time);
        $date = trim($date);
        $time = trim($time);

        if ($date === '') {
            return '';
        }

        $input = trim($date . ' ' . $time);
        try {
            $tz = new \DateTimeZone('Asia/Karachi');
            $dt = new \DateTime($input, $tz);
            return $dt->format(\DateTime::ATOM);
        } catch (\Exception $e) {
            return $input;
        }
    }

    private function normalizeDateValue(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        $date = str_replace('.', '-', str_replace('/', '-', $date));
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $date, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{2})$/', $date, $m)) {
            $year = (int) $m[3];
            $year += $year < 50 ? 2000 : 1900;
            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }
        return $date;
    }

    private function normalizeTimeValue(string $time): string
    {
        $time = trim($time);
        if ($time === '') {
            return '';
        }
        $time = str_ireplace(['a.m.', 'p.m.'], ['AM', 'PM'], $time);
        $time = preg_replace('/\s+/', ' ', $time) ?? $time;
        $digits = preg_replace('/\D/', '', $time) ?? '';
        if (preg_match('/^\d{3,4}$/', $digits) && !str_contains($time, ':')) {
            if (strlen($digits) === 3) {
                $time = sprintf('%02d:%02d', (int) substr($digits, 0, 1), (int) substr($digits, 1));
            } else {
                $time = sprintf('%02d:%02d', (int) substr($digits, 0, 2), (int) substr($digits, 2));
            }
        }
        $time = str_ireplace([' am', ' pm'], [' AM', ' PM'], $time);
        return trim($time);
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
