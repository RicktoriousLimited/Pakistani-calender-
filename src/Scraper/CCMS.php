<?php
namespace SHUTDOWN\Scraper;

/**
 * Scrape PITC CCMS feeder shutdown listings. The upstream page occasionally
 * serves either a JSON payload (used by its AJAX widgets) or a rendered HTML
 * table, so we attempt both strategies. The class mirrors the injection-friendly
 * design of the other scrapers to keep tests fast and deterministic.
 */
class CCMS implements SourceInterface
{
    private string $url;

    /** @var callable|null */
    private $fetcher;

    public function __construct(string $url, ?callable $fetcher = null)
    {
        $this->url = $url;
        $this->fetcher = $fetcher;
    }

    private function fetchBody(string $url): ?string
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
            CURLOPT_USERAGENT => 'Shutdown/1.0 (+)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/html;q=0.9, */*;q=0.8',
                'Referer: https://ccms.pitc.com.pk/',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && $body) {
            return $body;
        }
        return null;
    }

    public function fetch(): array
    {
        $loader = $this->fetcher ?? [$this, 'fetchBody'];
        $body = $loader($this->url);
        if (!$body) {
            return [];
        }

        $items = $this->parseJson($body);
        if (!empty($items)) {
            return $items;
        }

        return $this->parseHtml($body);
    }

    private function parseJson(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = $this->extractRows($decoded);
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = $this->mapRow($row);
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    private function parseHtml(string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        if (!$dom->loadHTML($html)) {
            libxml_clear_errors();
            return [];
        }
        libxml_clear_errors();

        $items = [];
        $tables = $dom->getElementsByTagName('table');
        foreach ($tables as $table) {
            $headers = $this->extractHeaders($table);
            $rows = $table->getElementsByTagName('tr');
            foreach ($rows as $row) {
                $cells = $row->getElementsByTagName('td');
                if ($cells->length === 0) {
                    continue;
                }
                $assoc = [];
                $raw = [];
                foreach ($cells as $idx => $cell) {
                    $value = trim(preg_replace('/\s+/', ' ', $cell->textContent));
                    if ($value === '') {
                        continue;
                    }
                    $raw[$idx] = $value;
                    if (!empty($headers[$idx])) {
                        $assoc[$headers[$idx]] = $value;
                    }
                }
                if (empty($assoc) && !empty($raw)) {
                    $assoc = $this->guessFromCells($raw);
                }
                $item = $this->mapRow($assoc);
                if ($item) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<int, mixed>
     */
    private function extractRows(array $decoded): array
    {
        if (array_is_list($decoded)) {
            return $decoded;
        }

        $candidates = ['data', 'Data', 'items', 'Items', 'results', 'Results', 'value', 'Value', 'table', 'Table', 'rows', 'Rows', 'payload'];
        foreach ($candidates as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $decoded[$key];
            }
        }

        foreach ($decoded as $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    return $value;
                }
                // Nested associative array; treat as single record
                return [$value];
            }
        }

        return [$decoded];
    }

    /**
     * @param array<int, string> $cells
     * @return array<string, string>
     */
    private function guessFromCells(array $cells): array
    {
        $map = [];
        if (isset($cells[2])) {
            $map['SubDivision'] = $cells[2];
        }
        if (isset($cells[1]) && !isset($map['SubDivision'])) {
            $map['Area'] = $cells[1];
        }
        if (isset($cells[3])) {
            $map['FeederName'] = $cells[3];
        }
        if (!isset($map['FeederName']) && isset($cells[0])) {
            $map['FeederName'] = $cells[0];
        }
        if (isset($cells[4])) {
            $map['ShutdownDate'] = $cells[4];
        }
        if (isset($cells[5])) {
            $map['StartTime'] = $cells[5];
        }
        if (isset($cells[6])) {
            $map['EndTime'] = $cells[6];
        }
        if (isset($cells[7])) {
            $map['ShutdownType'] = $cells[7];
        }
        if (isset($cells[8])) {
            $map['Reason'] = $cells[8];
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function extractHeaders(\DOMNode $table): array
    {
        $headers = [];
        $idx = 0;
        foreach ($table->childNodes as $section) {
            foreach ($section->childNodes as $row) {
                if (!$row instanceof \DOMElement || strtolower($row->nodeName) !== 'tr') {
                    continue;
                }
                foreach ($row->childNodes as $cell) {
                    if (!$cell instanceof \DOMElement || strtolower($cell->nodeName) !== 'th') {
                        continue;
                    }
                    $headers[$idx] = $this->headerToKey($cell->textContent);
                    $idx++;
                }
                if (!empty($headers)) {
                    return $headers;
                }
            }
        }

        return $headers;
    }

    private function headerToKey(string $text): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $text)));
        if ($normalized === '') {
            return '';
        }

        return match (true) {
            str_contains($normalized, 'sub') && str_contains($normalized, 'division') => 'SubDivision',
            str_contains($normalized, 'feeder') => 'FeederName',
            str_contains($normalized, 'shutdown') && str_contains($normalized, 'date') => 'ShutdownDate',
            str_contains($normalized, 'start') => 'StartTime',
            (str_contains($normalized, 'end') || str_contains($normalized, 'to')) => 'EndTime',
            str_contains($normalized, 'reason') || str_contains($normalized, 'remark') => 'Reason',
            str_contains($normalized, 'type') => 'ShutdownType',
            str_contains($normalized, 'circle') => 'Circle',
            str_contains($normalized, 'division') => 'Division',
            str_contains($normalized, 'town') || str_contains($normalized, 'area') => 'Area',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): ?array
    {
        if ($row === []) {
            return null;
        }

        $start = $this->resolveStart($row);
        if (!$start) {
            return null;
        }

        $end = $this->resolveEnd($row, $start);
        $area = $this->firstNonEmpty($row, ['SubDivision', 'Subdivision', 'Area', 'AreaName', 'Town', 'Circle', 'Division', 'GridStation']);
        $feeder = $this->firstNonEmpty($row, ['FeederName', 'Feeder', 'Feeder_Code', 'FeederCode', 'FeederId', 'Feeder ID', 'FeederDescription']);
        $reason = $this->firstNonEmpty($row, ['Reason', 'reason', 'Remarks', 'Remark', 'Purpose', 'ShutdownType', 'Type', 'Category']);
        $type = $this->firstNonEmpty($row, ['Type', 'type', 'ShutdownType', 'Category']);

        if (!$type && $reason) {
            $type = stripos($reason, 'maint') !== false ? 'maintenance' : 'scheduled';
        }

        if (!$area && isset($row['Division'])) {
            $area = (string) $row['Division'];
        }

        if (!$feeder && isset($row['Circle'])) {
            $feeder = (string) $row['Circle'];
        }

        if ($area === '' && $feeder === '') {
            return null;
        }

        return [
            'utility' => 'LESCO',
            'area' => $area,
            'feeder' => $feeder,
            'start' => $start,
            'end' => $end,
            'type' => $type ?: 'scheduled',
            'reason' => $reason ?: '',
            'source' => 'ccms',
            'url' => $this->url,
            'confidence' => 0.8,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveStart(array $row): ?string
    {
        $directKeys = ['start', 'Start', 'StartDateTime', 'startDateTime', 'StartDateTimeString', 'FromDateTime', 'fromDateTime'];
        foreach ($directKeys as $key) {
            if (!empty($row[$key])) {
                $value = $this->cleanValue($row[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $date = $this->firstNonEmpty($row, ['StartDate', 'startDate', 'ShutdownDate', 'Date', 'date', 'FromDate', 'fromDate']);
        $time = $this->firstNonEmpty($row, ['StartTime', 'startTime', 'FromTime', 'fromTime', 'From', 'from']);

        return $this->combineDateTime($date, $time);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveEnd(array $row, ?string $start): ?string
    {
        $directKeys = ['end', 'End', 'EndDateTime', 'endDateTime', 'ToDateTime', 'toDateTime'];
        foreach ($directKeys as $key) {
            if (!empty($row[$key])) {
                $value = $this->cleanValue($row[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $date = $this->firstNonEmpty($row, ['EndDate', 'endDate', 'ToDate', 'toDate', 'ShutdownDate', 'Date', 'date']);
        if (!$date && $start) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $start, $m)) {
                $date = $m[1];
            }
        }
        $time = $this->firstNonEmpty($row, ['EndTime', 'endTime', 'ToTime', 'toTime', 'TillTime', 'tillTime', 'till']);

        return $this->combineDateTime($date, $time);
    }

    private function combineDateTime(?string $date, ?string $time): ?string
    {
        $date = $this->cleanValue($date);
        $time = $this->cleanValue($time);
        if ($date && $time) {
            return trim($date . ' ' . $time);
        }
        if ($date) {
            return $date;
        }
        if ($time) {
            return $time;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $keys
     */
    private function firstNonEmpty(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $value = $this->cleanValue($row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function cleanValue($value): string
    {
        if ($value === null) {
            return '';
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return '';
        }
        $normalized = preg_replace('/\s+/', ' ', $trimmed);
        if ($normalized === null) {
            return '';
        }
        return trim($normalized);
    }
}
