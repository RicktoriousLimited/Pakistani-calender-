<?php
namespace SHUTDOWN\Parser;

class ManualCsv
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $fh = fopen($this->path, 'r');
        if (!$fh) {
            return [];
        }
        $headers = fgetcsv($fh, 0, ',', '"', '\\');
        if (!$headers) {
            fclose($fh);
            return [];
        }
        $rows = [];
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            if (count($row) < count($headers)) {
                $row = array_pad($row, count($headers), '');
            }
            $rows[] = array_combine($headers, $row);
        }
        fclose($fh);

        return array_map(static function (array $r): array {
            return [
                'utility' => $r['utility'] ?? 'LESCO',
                'area' => $r['area'] ?? '',
                'feeder' => $r['feeder'] ?? '',
                'start' => $r['start'] ?? '',
                'end' => $r['end'] ?? '',
                'type' => $r['type'] ?? 'scheduled',
                'reason' => $r['reason'] ?? '',
                'source' => $r['source'] ?? 'manual',
                'url' => $r['url'] ?? '',
                'confidence' => isset($r['confidence']) ? (float) $r['confidence'] : 0.8,
            ];
        }, $rows);
    }
}
