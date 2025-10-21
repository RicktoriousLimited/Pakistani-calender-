<?php
namespace SHUTDOWN\Util;

class Exporter
{
    public static function toCsv(array $items): string
    {
        $headers = ['utility', 'area', 'feeder', 'division', 'start', 'end', 'type', 'reason', 'source', 'url', 'confidence'];
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $headers, ',', '"', '\\');
        foreach ($items as $item) {
            $row = [];
            foreach ($headers as $field) {
                $value = $item[$field] ?? '';
                $row[] = is_float($value) ? (string) $value : $value;
            }
            fputcsv($fh, $row, ',', '"', '\\');
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $csv;
    }

    public static function toJson(array $items): string
    {
        return json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public static function toIcs(array $items, string $calendarName = 'Shutdown Schedule'): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Shutdown//Schedule//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            self::fold('X-WR-CALNAME:' . self::escape($calendarName)),
        ];

        foreach ($items as $item) {
            $uid = sha1(($item['feeder'] ?? '') . ($item['start'] ?? '') . ($item['end'] ?? '')) . '@shutdown';
            $start = self::formatDate($item['start'] ?? null);
            $end = self::formatDate($item['end'] ?? null);
            if (!$start) {
                continue;
            }
            $summary = $item['area'] ?? 'Shutdown';
            if (!empty($item['division'])) {
                $summary .= ' – ' . $item['division'];
            }
            $descriptionParts = [
                $item['feeder'] ?? '',
                $item['reason'] ?? '',
                $item['source'] ?? '',
            ];
            $description = implode('\n', array_filter(array_map('trim', $descriptionParts)));
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            $lines[] = 'DTSTART:' . $start;
            if ($end) {
                $lines[] = 'DTEND:' . $end;
            }
            $lines[] = self::fold('SUMMARY:' . self::escape($summary));
            if ($description !== '') {
                $lines[] = self::fold('DESCRIPTION:' . self::escape($description));
            }
            if (!empty($item['url'])) {
                $lines[] = self::fold('URL;VALUE=URI:' . self::escape($item['url']));
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }

    private static function fold(string $line): string
    {
        $output = '';
        $len = strlen($line);
        for ($i = 0; $i < $len; $i += 70) {
            $segment = substr($line, $i, 70);
            if ($i === 0) {
                $output .= $segment;
            } else {
                $output .= "\r\n " . $segment;
            }
        }
        return $output;
    }

    private static function formatDate(?string $iso): ?string
    {
        if (!$iso) {
            return null;
        }
        $timestamp = strtotime($iso);
        if ($timestamp === false) {
            return null;
        }
        return gmdate('Ymd\THis\Z', $timestamp);
    }
}
