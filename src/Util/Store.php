<?php
namespace SHUTDOWN\Util;

use SHUTDOWN\Parser\ManualCsv;

class Store
{
    private string $dir;
    private string $schedulePath;
    private string $logPath;
    private string $historyDir;
    private string $changelogPath;
    private string $configPath;
    private string $manualPath;
    private string $areasPath;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $areasCache = null;

    public function __construct(string $dir)
    {
        $this->dir = $dir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $this->schedulePath = $dir . DIRECTORY_SEPARATOR . 'schedule.json';
        $this->logPath = $dir . DIRECTORY_SEPARATOR . 'sources.log';
        $this->historyDir = $dir . DIRECTORY_SEPARATOR . 'history';
        $this->changelogPath = $dir . DIRECTORY_SEPARATOR . 'changelog.ndjson';
        $this->configPath = $dir . DIRECTORY_SEPARATOR . 'config.json';
        $this->manualPath = $dir . DIRECTORY_SEPARATOR . 'manual.csv';
        $this->areasPath = $dir . DIRECTORY_SEPARATOR . 'areas.json';

        if (!is_dir($this->historyDir)) {
            @mkdir($this->historyDir, 0775, true);
        }
        if (!file_exists($this->schedulePath)) {
            file_put_contents($this->schedulePath, json_encode(['updatedAt' => null, 'items' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        if (!file_exists($this->configPath)) {
            $default = [
                'timezone' => 'Asia/Karachi',
                'sources' => [
                    'official' => ['enabled' => true, 'url' => 'https://www.lesco.gov.pk/shutdownschedule'],
                    'facebook' => ['enabled' => false, 'url' => 'https://www.facebook.com/PRLESCO/'],
                    'ccms' => [
                        'enabled' => true,
                        'url' => 'https://ccms.pitc.com.pk/FeederDetails',
                        'note' => 'PITC CCMS official schedule (JSON/HTML)',
                    ],
                    'pdf' => [
                        'enabled' => true,
                        'url' => '',
                        'discover' => [
                            'https://www.lesco.gov.pk/shutdownschedule',
                            'https://www.lesco.gov.pk/LoadSheddingShutdownSchedule',
                            'https://www.lesco.gov.pk/LoadManagement',
                        ],
                        'note' => 'Automatically discovers the latest bulletin when URL is empty',
                    ],
                    'manual' => ['enabled' => true],
                ],
            ];
            file_put_contents($this->configPath, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function readConfig(): array
    {
        $txt = @file_get_contents($this->configPath);
        return $txt ? json_decode($txt, true) : [];
    }

    public function writeConfig(array $cfg): void
    {
        file_put_contents($this->configPath, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function readSchedule(): array
    {
        $json = @file_get_contents($this->schedulePath);
        $data = $json ? json_decode($json, true) : ['updatedAt' => null, 'items' => []];
        $items = $data['items'] ?? [];
        $data['items'] = array_map(fn ($it) => $this->enrichItem($it), $items);
        return $data;
    }

    private function keyOf(array $it): string
    {
        return strtolower(($it['feeder'] ?? '')) . '|' . ($it['start'] ?? '') . '|' . ($it['end'] ?? '');
    }

    public function writeSchedule(array $items): void
    {
        $items = array_map(fn ($it) => $this->enrichItem($it), $items);
        $prev = $this->readSchedule();
        $oldItems = $prev['items'] ?? [];
        $payload = ['updatedAt' => gmdate('c'), 'items' => $items];
        // atomic write
        $tmp = $this->schedulePath . '.tmp';
        $fp = fopen($tmp, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open temp file');
        }
        if (!flock($fp, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock temp file');
        }
        ftruncate($fp, 0);
        fwrite($fp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        rename($tmp, $this->schedulePath);
        // history (day file)
        $day = (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $dayPath = $this->historyDir . DIRECTORY_SEPARATOR . $day . '.json';
        $existing = [];
        if (file_exists($dayPath)) {
            $existing = json_decode(file_get_contents($dayPath), true) ?: [];
        }
        $map = [];
        foreach ($existing as $ex) {
            $map[$this->keyOf($ex)] = $ex;
        }
        foreach ($items as $it) {
            $map[$this->keyOf($it)] = $it;
        }
        file_put_contents($dayPath, json_encode(array_values($map), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        // changelog
        $added = 0;
        $removed = 0;
        $oldMap = [];
        foreach ($oldItems as $o) {
            $oldMap[$this->keyOf($o)] = true;
        }
        $newMap = [];
        foreach ($items as $n) {
            $newMap[$this->keyOf($n)] = true;
        }
        foreach ($newMap as $k => $_) {
            if (!isset($oldMap[$k])) {
                $added++;
            }
        }
        foreach ($oldMap as $k => $_) {
            if (!isset($newMap[$k])) {
                $removed++;
            }
        }
        file_put_contents($this->changelogPath, json_encode(['ts' => gmdate('c'), 'added' => $added, 'removed' => $removed]) . "\n", FILE_APPEND);
    }

    public function backupZip(string $destPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($destPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot open zip');
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir));
        foreach ($it as $file) {
            if ($file->isDir()) {
                continue;
            }
            $real = $file->getRealPath();
            if ($real === false) {
                continue;
            }
            $rel = substr($real, strlen($this->dir) + 1);
            $zip->addFile($real, $rel);
        }
        $zip->close();
    }

    public function meta(): array
    {
        $st = @stat($this->schedulePath);
        return ['path' => $this->schedulePath, 'size' => $st ? $st['size'] : 0, 'mtime' => $st ? date('c', $st['mtime']) : null];
    }

    public function manualPath(): string
    {
        return $this->manualPath;
    }

    public function readManual(): array
    {
        return (new ManualCsv($this->manualPath))->read();
    }

    public function appendManualEntry(array $entry): array
    {
        $normalized = Merge::normalize($entry);
        $normalized['source'] = $normalized['source'] ?: 'manual';
        $normalized['confidence'] = $normalized['confidence'] ?: 0.8;

        $headers = ['utility', 'area', 'feeder', 'start', 'end', 'type', 'reason', 'source', 'url', 'confidence'];
        $exists = file_exists($this->manualPath);
        $fh = fopen($this->manualPath, 'a');
        if (!$fh) {
            throw new \RuntimeException('Cannot open manual.csv for writing');
        }
        if (!$exists) {
            fputcsv($fh, $headers, ',', '"', '\\');
        }
        $row = [];
        foreach ($headers as $field) {
            $value = $normalized[$field] ?? '';
            $row[] = is_float($value) ? (string) $value : $value;
        }
        fputcsv($fh, $row, ',', '"', '\\');
        fclose($fh);
        return $normalized;
    }

    public function readAreas(): array
    {
        if ($this->areasCache !== null) {
            return $this->areasCache;
        }
        if (!is_file($this->areasPath)) {
            $this->areasCache = [];
            return $this->areasCache;
        }
        $json = file_get_contents($this->areasPath);
        $this->areasCache = $json ? json_decode($json, true) : [];
        return $this->areasCache ?? [];
    }

    public function listDivisions(): array
    {
        $areas = $this->readAreas();
        $divs = [];
        foreach ($areas as $meta) {
            if (!empty($meta['division'])) {
                $divs[] = $meta['division'];
            }
        }
        $divs = array_values(array_unique($divs));
        sort($divs);
        return $divs;
    }

    public function enrichItem(array $item): array
    {
        $item['area'] = trim((string)($item['area'] ?? ''));
        $item['feeder'] = trim((string)($item['feeder'] ?? ''));
        $item['division'] = $item['division'] ?? null;
        $areas = $this->readAreas();
        if ($item['area'] !== '') {
            $key = strtolower($item['area']);
            if (isset($areas[$key])) {
                $meta = $areas[$key];
                if (!empty($meta['division'])) {
                    $item['division'] = $meta['division'];
                }
                foreach (['lat', 'lng'] as $coord) {
                    if (isset($meta[$coord]) && !isset($item[$coord])) {
                        $item[$coord] = $meta[$coord];
                    }
                }
            }
        }
        return $item;
    }

    public function filterItems(array $items, array $filters): array
    {
        $q = strtolower(trim((string)($filters['q'] ?? '')));
        $area = strtolower(trim((string)($filters['area'] ?? '')));
        $feeder = strtolower(trim((string)($filters['feeder'] ?? '')));
        $division = strtolower(trim((string)($filters['division'] ?? '')));
        $date = $filters['date'] ?? null;
        $dayStart = $date ? strtotime($date . ' 00:00:00') : null;
        $dayEnd = $date ? strtotime($date . ' 23:59:59') : null;

        $hasFilters = $q !== '' || $area !== '' || $feeder !== '' || $division !== '' || !empty($date);

        $filtered = array_filter($items, static function ($it) use ($q, $area, $feeder, $division, $dayStart, $dayEnd) {
            $hay = strtolower(($it['area'] ?? '') . ' ' . ($it['feeder'] ?? '') . ' ' . ($it['reason'] ?? ''));
            if ($q && strpos($hay, $q) === false) {
                return false;
            }
            if ($area && strpos(strtolower($it['area'] ?? ''), $area) === false) {
                return false;
            }
            if ($feeder && strpos(strtolower($it['feeder'] ?? ''), $feeder) === false) {
                return false;
            }
            if ($division && strpos(strtolower($it['division'] ?? ''), $division) === false) {
                return false;
            }
            if ($dayStart && !empty($it['start'])) {
                $t = strtotime((string) $it['start']);
                if ($t < $dayStart || $t > $dayEnd) {
                    return false;
                }
            }
            return true;
        });
        $filtered = array_values($filtered);

        usort($filtered, static function ($a, $b): int {
            return strcmp($a['start'] ?? '', $b['start'] ?? '');
        });

        if (!$hasFilters) {
            $now = time();
            $upcoming = array_filter($filtered, static function ($it) use ($now) {
                if (empty($it['start'])) {
                    return true;
                }
                $ts = strtotime((string) $it['start']);
                if ($ts === false) {
                    return true;
                }
                return $ts >= $now - 3600;
            });
            if (!empty($upcoming)) {
                $filtered = array_values($upcoming);
            }
            $filtered = array_slice($filtered, 0, 200);
        }

        return $filtered;
    }

    public function readHistory(string $day): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new \InvalidArgumentException('Invalid day format');
        }
        $path = $this->historyDir . DIRECTORY_SEPARATOR . $day . '.json';
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        $items = $json ? json_decode($json, true) : [];
        return array_map(fn ($it) => $this->enrichItem($it), $items ?: []);
    }

    public function readChangelog(int $limit = 50): array
    {
        if (!is_file($this->changelogPath)) {
            return [];
        }
        $lines = file($this->changelogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice(array_reverse($lines), 0, max(1, $limit));
        return array_values(array_map(static function ($line) {
            $decoded = json_decode($line, true);
            return $decoded ?: ['raw' => $line];
        }, $lines));
    }
}
