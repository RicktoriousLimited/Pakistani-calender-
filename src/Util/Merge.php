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
            'utility' => trim((string)($it['utility'] ?? 'LESCO')) ?: 'LESCO',
            'area' => trim((string)($it['area'] ?? '')),
            'feeder' => trim((string)($it['feeder'] ?? '')),
            'start' => $start,
            'end' => $end,
            'type' => trim((string)($it['type'] ?? '')) ?: 'scheduled',
            'reason' => trim((string)($it['reason'] ?? '')),
            'source' => trim((string)($it['source'] ?? '')),
            'url' => trim((string)($it['url'] ?? '')),
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
        $groups = [];
        foreach ($arrays as $arr) {
            foreach ($arr as $x) {
                $normalized = self::normalize($x);
                $key = strtolower($normalized['feeder'] ?? '') . '|' . ($normalized['start'] ?? '') . '|' . ($normalized['end'] ?? '');
                $groups[$key][] = $normalized;
            }
        }

        $result = [];
        foreach ($groups as $items) {
            if (empty($items)) {
                continue;
            }
            usort($items, static fn ($a, $b): int => $b['confidence'] <=> $a['confidence']);
            $primary = $items[0];
            $fields = ['area', 'feeder', 'start', 'end', 'type', 'reason', 'source', 'url'];
            foreach ($fields as $field) {
                if (self::isEmptyValue($primary[$field] ?? null)) {
                    foreach ($items as $candidate) {
                        if (!self::isEmptyValue($candidate[$field] ?? null)) {
                            $primary[$field] = $candidate[$field];
                            break;
                        }
                    }
                }
            }
            $primary['sources'] = array_values(array_unique(array_filter(array_map(
                static fn ($candidate) => $candidate['source'] ?? '',
                $items
            ), static fn ($value) => trim((string) $value) !== '')));
            $result[] = $primary;
        }

        usort($result, static function ($x, $y): int {
            return strcmp($x['start'] ?? '', $y['start'] ?? '');
        });

        return $result;
    }

    private static function isEmptyValue($value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        return false;
    }
}
