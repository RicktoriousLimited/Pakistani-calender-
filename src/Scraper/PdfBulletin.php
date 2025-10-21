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

    public function __construct(string $url, ?callable $fetcher = null)
    {
        $this->url = $url;
        $this->fetcher = $fetcher;
    }

    public function fetch(): array
    {
        $loader = $this->fetcher ?? [$this, 'download'];
        $binary = $loader($this->url);
        if (!is_string($binary) || $binary === '') {
            return [];
        }

        $text = PdfTextExtractor::extractText($binary);
        if ($text === '') {
            return [];
        }

        return $this->parseText($text);
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
            'url' => $this->url,
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
