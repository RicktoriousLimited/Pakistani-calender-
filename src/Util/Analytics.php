<?php
namespace SHUTDOWN\Util;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class Analytics
{
    private DateTimeZone $zone;

    public function __construct(string $timezone = 'Asia/Karachi')
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            $timezone = 'UTC';
        }
        $this->zone = new DateTimeZone($timezone);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function forecast(array $items, int $days = 7, ?DateTimeImmutable $now = null): array
    {
        $days = max(1, $days);
        $now = $now ? $now->setTimezone($this->zone) : new DateTimeImmutable('now', $this->zone);
        $windowEnd = $now->add(new DateInterval('P' . $days . 'D'));
        $window24h = $now->add(new DateInterval('P1D'));

        $daily = [];
        $divisionStats = [];
        $types = [];
        $areas = [];
        $upcoming = [];
        $longest = [];

        $totalHours = 0.0;
        $count = 0;
        $within24h = 0;
        $nextStart = null;

        foreach ($items as $item) {
            $start = $this->parseDate($item['start'] ?? null);
            if (!$start) {
                continue;
            }
            if ($start->getTimestamp() < ($now->getTimestamp() - 3600)) {
                continue;
            }
            if ($start > $windowEnd) {
                continue;
            }
            $end = $this->parseDate($item['end'] ?? null, $start);
            if ($end && $end < $start) {
                $end = $start;
            }
            $duration = $this->durationHours($start, $end);

            $count++;
            $totalHours += $duration;

            $area = trim((string)($item['area'] ?? ''));
            $division = trim((string)($item['division'] ?? ''));
            $feeder = trim((string)($item['feeder'] ?? ''));
            $type = strtoupper(trim((string)($item['type'] ?? 'scheduled')) ?: 'SCHEDULED');

            if ($area !== '') {
                $areas[$area] = true;
            }

            $dayKey = $start->format('Y-m-d');
            if (!isset($daily[$dayKey])) {
                $daily[$dayKey] = [
                    'date' => $dayKey,
                    'label' => $start->format('D d M'),
                    'count' => 0,
                    'totalHours' => 0.0,
                    'areas' => [],
                    'firstStart' => null,
                    'lastEnd' => null,
                ];
            }
            $daily[$dayKey]['count']++;
            $daily[$dayKey]['totalHours'] += $duration;
            if ($area !== '') {
                $daily[$dayKey]['areas'][$area] = true;
            }
            if (!$daily[$dayKey]['firstStart'] || $start < $daily[$dayKey]['firstStart']) {
                $daily[$dayKey]['firstStart'] = $start;
            }
            if ($end && (!$daily[$dayKey]['lastEnd'] || $end > $daily[$dayKey]['lastEnd'])) {
                $daily[$dayKey]['lastEnd'] = $end;
            }

            $divisionKey = $division !== '' ? $division : 'Unspecified';
            if (!isset($divisionStats[$divisionKey])) {
                $divisionStats[$divisionKey] = [
                    'division' => $divisionKey,
                    'count' => 0,
                    'totalHours' => 0.0,
                    'areas' => [],
                ];
            }
            $divisionStats[$divisionKey]['count']++;
            $divisionStats[$divisionKey]['totalHours'] += $duration;
            if ($area !== '') {
                $divisionStats[$divisionKey]['areas'][$area] = true;
            }

            if (!isset($types[$type])) {
                $types[$type] = 0;
            }
            $types[$type]++;

            if ($start <= $window24h) {
                $within24h++;
            }
            if ($nextStart === null || $start < $nextStart) {
                $nextStart = $start;
            }

            $entry = [
                'area' => $area !== '' ? $area : 'Unspecified area',
                'division' => $division !== '' ? $division : null,
                'feeder' => $feeder !== '' ? $feeder : null,
                'start' => $start->format(DateTimeInterface::ATOM),
                'end' => $end ? $end->format(DateTimeInterface::ATOM) : null,
                'hours' => round($duration, 2),
                'type' => $item['type'] ?? null,
                'reason' => $item['reason'] ?? null,
                'source' => $item['source'] ?? null,
                'confidence' => isset($item['confidence']) ? (float) $item['confidence'] : null,
            ];
            $upcoming[] = ['start' => $start, 'entry' => $entry];
            $longest[] = ['duration' => $duration, 'entry' => $entry];
        }

        usort($upcoming, static fn ($a, $b) => $a['start'] <=> $b['start']);
        $upcomingList = array_slice(array_map(static fn ($row) => $row['entry'], $upcoming), 0, 5);

        usort($longest, static function ($a, $b) {
            $cmp = $b['duration'] <=> $a['duration'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['entry']['start'] ?? '', $b['entry']['start'] ?? '');
        });
        $longestList = array_slice(array_map(static fn ($row) => $row['entry'], $longest), 0, 5);

        $dailyOut = array_map(static function (array $row) {
            return [
                'date' => $row['date'],
                'label' => $row['label'],
                'count' => $row['count'],
                'totalHours' => round($row['totalHours'], 2),
                'distinctAreas' => count($row['areas']),
                'firstStart' => $row['firstStart'] instanceof DateTimeImmutable ? $row['firstStart']->format(DateTimeInterface::ATOM) : null,
                'lastEnd' => $row['lastEnd'] instanceof DateTimeImmutable ? $row['lastEnd']->format(DateTimeInterface::ATOM) : null,
            ];
        }, $daily);
        usort($dailyOut, static fn ($a, $b) => strcmp($a['date'], $b['date']));

        $divisionOut = array_map(static function (array $row) {
            return [
                'division' => $row['division'],
                'count' => $row['count'],
                'totalHours' => round($row['totalHours'], 2),
                'distinctAreas' => count($row['areas']),
            ];
        }, $divisionStats);
        usort($divisionOut, static function ($a, $b) {
            $cmp = $b['count'] <=> $a['count'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return $b['totalHours'] <=> $a['totalHours'];
        });
        $divisionOut = array_slice($divisionOut, 0, 5);

        $typesOut = [];
        foreach ($types as $name => $value) {
            $share = $count > 0 ? round(($value / $count) * 100, 2) : 0.0;
            $typesOut[] = ['type' => $name, 'count' => $value, 'share' => $share];
        }
        usort($typesOut, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'ok' => true,
            'generatedAt' => gmdate(DateTimeInterface::ATOM),
            'window' => [
                'start' => $now->format(DateTimeInterface::ATOM),
                'end' => $windowEnd->format(DateTimeInterface::ATOM),
                'days' => $days,
            ],
            'totals' => [
                'count' => $count,
                'areas' => count($areas),
                'divisions' => count($divisionStats),
                'totalHours' => round($totalHours, 2),
                'averageHours' => $count > 0 ? round($totalHours / $count, 2) : 0.0,
                'within24h' => $within24h,
                'nextStart' => $nextStart ? $nextStart->format(DateTimeInterface::ATOM) : null,
            ],
            'daily' => $dailyOut,
            'divisions' => $divisionOut,
            'types' => $typesOut,
            'upcoming' => $upcomingList,
            'longest' => $longestList,
        ];
    }

    private function parseDate(?string $value, ?DateTimeImmutable $fallback = null): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return $fallback;
        }
        try {
            $dt = new DateTimeImmutable($value);
            return $dt->setTimezone($this->zone);
        } catch (\Exception $e) {
            return $fallback;
        }
    }

    private function durationHours(?DateTimeImmutable $start, ?DateTimeImmutable $end): float
    {
        if (!$start) {
            return 0.0;
        }
        if (!$end) {
            $end = $start->add(new DateInterval('PT1H'));
        }
        $seconds = max(0, $end->getTimestamp() - $start->getTimestamp());
        $hours = $seconds / 3600;
        if ($hours <= 0) {
            $hours = 1 / 60;
        }
        return round($hours, 2);
    }
}
