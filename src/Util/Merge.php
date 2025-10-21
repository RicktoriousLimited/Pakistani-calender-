<?php
namespace SHUTDOWN\Util;

class Merge
{
    /**
     * Normalise an item so downstream consumers can rely on consistent keys.
     */
    public static function normalize(array $it): array
    {
        $start = self::normalizeDate($it['start'] ?? null);
        $end = self::normalizeDate($it['end'] ?? null);

        if ($start !== null && $end !== null) {
            $startTs = strtotime($start) ?: null;
            $endTs = strtotime($end) ?: null;
            if ($startTs !== null && $endTs !== null && $endTs < $startTs) {
                $end = $start;
            }
        }

        return [
            'utility' => $it['utility'] ?? 'LESCO',
            'area' => trim((string)($it['area'] ?? '')),
            'feeder' => trim((string)($it['feeder'] ?? '')),
            'start' => $start,
            'end' => $end,
            'type' => $it['type'] ?? 'scheduled',
            'reason' => $it['reason'] ?? '',
            'source' => $it['source'] ?? '',
            'url' => $it['url'] ?? '',
            'confidence' => isset($it['confidence']) ? (float) $it['confidence'] : 0.7,
        ];
    }

    private static function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string) $value);
        if ($ts === false) {
            return null;
        }
        return date('c', $ts);
    }

    /**
     * Merge multiple source arrays, deduplicating by feeder/time window and
     * keeping the entry with the highest confidence value.
     *
     * @param array<int, array<int, array<string, mixed>>> $arrays
     * @return array<int, array<string, mixed>>
     */
    public static function merge(array $arrays): array
    {
        $all = [];
        foreach ($arrays as $arr) {
            foreach ($arr as $x) {
                $all[] = self::normalize($x);
            }
        }

        $map = [];
        foreach ($all as $it) {
            $key = strtolower($it['feeder'] ?? '') . '|' . ($it['start'] ?? '') . '|' . ($it['end'] ?? '');
            if (!isset($map[$key]) || $it['confidence'] > $map[$key]['confidence']) {
                $map[$key] = $it;
            }
        }

        $arr = array_values($map);
        usort($arr, static function ($x, $y): int {
            return strcmp($x['start'] ?? '', $y['start'] ?? '');
        });

        return $arr;
    }
}
