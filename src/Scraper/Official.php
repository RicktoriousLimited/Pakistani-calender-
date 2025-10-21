<?php
namespace SHUTDOWN\Scraper;

class Official implements SourceInterface
{
    private string $url;

    /** @var callable|null */
    private $fetcher;

    public function __construct(string $url, ?callable $fetcher = null)
    {
        $this->url = $url;
        $this->fetcher = $fetcher;
    }

    private function fetchHtml(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Shutdown/1.0 (+)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && $html) {
            return $html;
        }
        return null;
    }

    public function fetch(): array
    {
        $loader = $this->fetcher ?? [$this, 'fetchHtml'];
        $html = $loader($this->url);
        if (!$html) {
            return [];
        }
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $rows = $xpath->query('//table//tr');
        $items = [];
        foreach ($rows as $tr) {
            $cells = [];
            foreach ($tr->getElementsByTagName('td') as $td) {
                $cells[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
            }
            if (count($cells) >= 4) {
                [$area, $feeder, $start, $end] = array_slice($cells, 0, 4);
                $reason = $cells[4] ?? '';
                if ($area && $feeder && $start) {
                    $items[] = [
                        'utility' => 'LESCO',
                        'area' => $area,
                        'feeder' => $feeder,
                        'start' => $start,
                        'end' => $end,
                        'type' => 'scheduled',
                        'reason' => $reason,
                        'source' => 'official',
                        'url' => $this->url,
                        'confidence' => 0.9,
                    ];
                }
            }
        }

        return $items;
    }
}
