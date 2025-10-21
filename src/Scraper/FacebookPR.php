<?php
namespace SHUTDOWN\Scraper;

/**
 * Simple HTML scrape of public PR LESCO page (no login; brittle by nature).
 * Looks for common phrases and datetime-like strings.
 */
class FacebookPR implements SourceInterface
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
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
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
        $items = [];
        // naive extraction of text blocks
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $m)) {
            foreach ($m[1] as $para) {
                $text = trim(strip_tags($para));
                if (stripos($text, 'shutdown') !== false || stripos($text, 'load') !== false) {
                    // attempt area/feeder/time inference
                    preg_match('/(\d{4}-\d{2}-\d{2}|\d{1,2}[:\.]\d{2}\s*(AM|PM)?)/i', $text, $tm);
                    $items[] = [
                        'utility' => 'LESCO',
                        'area' => '',
                        'feeder' => '',
                        'start' => $tm[0] ?? '',
                        'end' => '',
                        'type' => 'scheduled',
                        'reason' => mb_substr($text, 0, 160) . '…',
                        'source' => 'facebook',
                        'url' => $this->url,
                        'confidence' => 0.5,
                    ];
                }
            }
        }
        return $items;
    }
}
