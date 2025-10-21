<?php
declare(strict_types=1);

error_reporting(E_ALL);
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('UTC');
}

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/support/FakeSource.php';

use SHUTDOWN\Parser\ManualCsv;
use SHUTDOWN\Scraper\CCMS;
use SHUTDOWN\Scraper\FacebookPR;
use SHUTDOWN\Scraper\LescoScraper;
use SHUTDOWN\Scraper\Official;
use SHUTDOWN\Scraper\PdfBulletin;
use SHUTDOWN\Util\Exporter;
use SHUTDOWN\Util\Merge;
use SHUTDOWN\Util\PdfTextExtractor;
use SHUTDOWN\Util\Store;
use Tests\Support\FakeSource;
use Tests\Support\ThrowingSource;

$tests = [];
$messages = [];

function test(string $name, callable $fn): void {
    global $tests;
    $tests[] = ['name' => $name, 'fn' => $fn];
}

function assertSame($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        $msg = $message ?: sprintf("Failed asserting that %s is identical to %s", var_export($actual, true), var_export($expected, true));
        throw new RuntimeException($msg);
    }
}

function assertEquals($expected, $actual, string $message = ''): void {
    if ($expected != $actual) {
        $msg = $message ?: sprintf("Failed asserting equality. Expected %s got %s", var_export($expected, true), var_export($actual, true));
        throw new RuntimeException($msg);
    }
}

function assertTrue($condition, string $message = ''): void {
    if (!$condition) {
        throw new RuntimeException($message ?: 'Assertion failed');
    }
}

function assertCount(int $expected, $value, string $message = ''): void {
    $count = is_countable($value) ? count($value) : null;
    if ($count !== $expected) {
        $msg = $message ?: sprintf('Expected count %d, got %s', $expected, var_export($count, true));
        throw new RuntimeException($msg);
    }
}

function withTempDir(callable $fn): void {
    $dir = sys_get_temp_dir() . '/shutdown-test-' . bin2hex(random_bytes(6));
    if (!mkdir($dir) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create temp dir');
    }
    try {
        $fn($dir);
    } finally {
        rrmdir($dir);
    }
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

test('Merge normalises and deduplicates', function (): void {
    $normalized = Merge::normalize([
        'area' => '  Test Area ',
        'feeder' => ' F-1 ',
        'start' => '2025-01-01 10:00',
        'end' => 'invalid',
        'confidence' => '0.5',
    ]);
    assertSame('Test Area', $normalized['area']);
    assertSame('F-1', $normalized['feeder']);
    assertTrue(strpos($normalized['start'] ?? '', '2025-01-01') === 0);
    assertSame(null, $normalized['end']);

    $withInvertedTimes = Merge::normalize([
        'start' => '2025-01-01T12:00:00+05:00',
        'end' => '2025-01-01T08:00:00+05:00',
    ]);
    assertTrue(strtotime((string) $withInvertedTimes['end']) >= strtotime((string) $withInvertedTimes['start']));

    $merged = Merge::merge([
        [
            ['feeder' => 'F', 'start' => '2025-01-01T08:00:00+05:00', 'confidence' => 0.6, 'reason' => 'old'],
            ['feeder' => 'F', 'start' => '2025-01-01T08:00:00+05:00', 'confidence' => 0.9, 'reason' => 'new'],
        ],
    ]);
    assertCount(1, $merged);
    assertSame('new', $merged[0]['reason']);
    assertSame(0.9, $merged[0]['confidence']);
});

test('ManualCsv parses rows', function (): void {
    withTempDir(function (string $dir): void {
        $path = $dir . '/manual.csv';
        file_put_contents($path, "utility,area,feeder,start,end,type,reason,source,url,confidence\nLESCO,Model Town,F1,2025-02-01T08:00:00+05:00,2025-02-01T12:00:00+05:00,scheduled,Tree trimming,manual,,0.85\n");
        $rows = (new ManualCsv($path))->read();
        assertCount(1, $rows);
        assertSame('Model Town', $rows[0]['area']);
        assertSame(0.85, $rows[0]['confidence']);
    });
});

test('Store append/read/manual/filter/history', function (): void {
    withTempDir(function (string $dir): void {
        file_put_contents($dir . '/areas.json', json_encode([
            'model town' => ['division' => 'Lahore Central'],
        ], JSON_PRETTY_PRINT));
        $store = new Store($dir);
        $entry = $store->appendManualEntry([
            'utility' => 'LESCO',
            'area' => 'Model Town',
            'feeder' => 'F1',
            'start' => '2099-03-01T08:00:00+05:00',
            'end' => '2099-03-01T10:00:00+05:00',
            'type' => 'maintenance',
        ]);
        assertSame('Model Town', $entry['area']);
        $manual = $store->readManual();
        assertCount(1, $manual);
        $store->writeSchedule([$entry]);
        $schedule = $store->readSchedule();
        assertSame('Lahore Central', $schedule['items'][0]['division']);
        $filtered = $store->filterItems($schedule['items'], ['division' => 'lahore central']);
        assertCount(1, $filtered);
        $filteredNone = $store->filterItems($schedule['items'], ['division' => 'lahore south']);
        assertCount(0, $filteredNone);
        $filteredDate = $store->filterItems($schedule['items'], ['date' => '2099-03-01']);
        assertCount(1, $filteredDate);
        $filteredDateNone = $store->filterItems($schedule['items'], ['date' => '2099-03-02']);
        assertCount(0, $filteredDateNone);
        $defaultList = $store->filterItems($schedule['items'], []);
        assertCount(1, $defaultList);
        $day = gmdate('Y-m-d');
        $history = $store->readHistory($day);
        assertCount(1, $history);
        $changelog = $store->readChangelog();
        assertTrue(count($changelog) >= 1);
        assertTrue(in_array('Lahore Central', $store->listDivisions(), true));
    });
});

test('Exporter formats outputs', function (): void {
    $items = [[
        'utility' => 'LESCO',
        'area' => 'Model Town',
        'division' => 'Central',
        'feeder' => 'F1',
        'start' => '2025-03-01T03:00:00Z',
        'end' => '2025-03-01T05:00:00Z',
        'type' => 'scheduled',
        'reason' => 'Test run',
        'source' => 'manual',
        'url' => 'https://example.com',
        'confidence' => 0.9,
    ]];
    $csv = Exporter::toCsv($items);
    assertTrue(strpos($csv, 'Model Town') !== false);
    $ics = Exporter::toIcs($items, 'Test Calendar');
    assertTrue(strpos($ics, 'BEGIN:VEVENT') !== false);
    assertTrue(strpos($ics, 'SUMMARY:Model Town') !== false);
    $json = Exporter::toJson($items);
    assertTrue(strpos($json, 'Model Town') !== false);
});

test('Store backup generates zip archive', function (): void {
    withTempDir(function (string $dir): void {
        $store = new Store($dir);
        $zip = $dir . '/backup.zip';
        $store->backupZip($zip);
        assertTrue(is_file($zip));
        assertTrue(filesize($zip) > 0);
    });
});

test('PdfTextExtractor extracts text from bulletin', function (): void {
    $pdf = file_get_contents(__DIR__ . '/support/sample-bulletin.pdf');
    assertTrue(is_string($pdf) && $pdf !== '');
    $text = PdfTextExtractor::extractText($pdf);
    assertTrue(strpos($text, 'Area: Model Town') !== false);
    $lines = preg_split('/\n/', $text);
    assertTrue(is_array($lines) && count($lines) >= 6);
});

test('PdfBulletin parses key value PDF bulletins', function (): void {
    $pdf = file_get_contents(__DIR__ . '/support/sample-bulletin.pdf');
    assertTrue(is_string($pdf));
    $bulletin = new PdfBulletin('https://example.test/bulletin.pdf', fn () => $pdf);
    $items = $bulletin->fetch();
    assertCount(2, $items);
    assertSame('Model Town', $items[0]['area']);
    assertSame('pdf', $items[0]['source']);
    assertTrue(strpos($items[1]['reason'], 'Tree') !== false);
});

test('PdfBulletin discovers latest PDF from listing pages', function (): void {
    $html = file_get_contents(__DIR__ . '/support/sample-bulletin-listing.html');
    $pdf = file_get_contents(__DIR__ . '/support/sample-bulletin.pdf');
    assertTrue(is_string($html));
    assertTrue(is_string($pdf));

    $fetcher = function (string $url) use ($html, $pdf) {
        if (str_ends_with($url, '.pdf')) {
            return $pdf;
        }
        return $html;
    };

    $bulletin = new PdfBulletin('https://example.test/bulletins', $fetcher);
    $items = $bulletin->fetch();
    assertCount(2, $items);
    assertSame('https://example.test/files/bulletins/2024-10-22-shutdown.pdf', $items[0]['url']);

    $fallbackBulletin = new PdfBulletin('', $fetcher, ['https://example.test/bulletins']);
    $fallbackItems = $fallbackBulletin->fetch();
    assertCount(2, $fallbackItems);
});

test('Official scraper parses table', function (): void {
    $html = '<table><tr><td>Area 1</td><td>F-1</td><td>2025-04-01 08:00</td><td>2025-04-01 10:00</td><td>Maintenance</td></tr></table>';
    $official = new Official('https://example.test', fn() => $html);
    $items = $official->fetch();
    assertCount(1, $items);
    assertSame('Area 1', $items[0]['area']);
    assertSame('F-1', $items[0]['feeder']);
});

test('CCMS scraper parses JSON payload', function (): void {
    $json = json_encode([
        'data' => [[
            'Circle' => 'Lahore Circle',
            'Division' => 'Model Town',
            'SubDivision' => 'Garden Town',
            'FeederName' => 'MT-01',
            'ShutdownDate' => '2025-04-02',
            'StartTime' => '08:00',
            'EndTime' => '12:00',
            'ShutdownType' => 'Maintenance',
            'Reason' => 'Tree trimming',
        ], [
            'Area' => 'Johar Town',
            'Feeder' => 'JT-11',
            'StartDateTime' => '2025-04-03T09:00:00',
            'EndDateTime' => '2025-04-03T13:00:00',
            'Type' => 'Upgrade',
        ]],
    ]);
    $ccms = new CCMS('https://example.test', fn () => $json);
    $items = $ccms->fetch();
    assertCount(2, $items);
    assertSame('Garden Town', $items[0]['area']);
    assertSame('MT-01', $items[0]['feeder']);
    assertTrue(strpos($items[0]['start'], '2025-04-02') === 0);
    assertSame('JT-11', $items[1]['feeder']);
    assertTrue(strpos($items[1]['start'], '2025-04-03') === 0);
    assertSame('ccms', $items[0]['source']);
});

test('CCMS scraper parses HTML table fallback', function (): void {
    $html = '<table><thead><tr><th>Circle</th><th>Division</th><th>Sub Division</th><th>Feeder Name</th><th>Shutdown Date</th><th>Start Time</th><th>End Time</th><th>Type</th><th>Remarks</th></tr></thead><tbody><tr><td>Lahore</td><td>Central</td><td>Allama Iqbal Town</td><td>AI-09</td><td>2025-04-05</td><td>07:30</td><td>11:30</td><td>Planned</td><td>System upgrade</td></tr></tbody></table>';
    $ccms = new CCMS('https://example.test', fn () => $html);
    $items = $ccms->fetch();
    assertCount(1, $items);
    assertSame('Allama Iqbal Town', $items[0]['area']);
    assertSame('AI-09', $items[0]['feeder']);
    assertTrue(strpos($items[0]['start'], '2025-04-05') === 0);
    assertSame('Planned', $items[0]['type']);
});

test('FacebookPR scraper extracts notice', function (): void {
    $html = '<div><p>Planned shutdown for feeder ABC on 2025-05-01 09:00</p></div>';
    $scraper = new FacebookPR('https://example.test', fn() => $html);
    $items = $scraper->fetch();
    assertCount(1, $items);
    assertTrue(stripos($items[0]['reason'], 'shutdown') !== false);
});

test('LescoScraper merges sources and reports', function (): void {
    withTempDir(function (string $dir): void {
        file_put_contents($dir . '/areas.json', json_encode([
            'model town' => ['division' => 'Lahore Central'],
        ], JSON_PRETTY_PRINT));
        $store = new Store($dir);
        $cfg = $store->readConfig();
        $cfg['sources']['official']['enabled'] = true;
        $cfg['sources']['manual']['enabled'] = true;
        $cfg['sources']['facebook']['enabled'] = false;
        $cfg['sources']['ccms']['enabled'] = false;
        $cfg['sources']['pdf']['enabled'] = false;
        $store->writeConfig($cfg);

        $factory = [
            'official' => fn () => new FakeSource([
                [
                    'area' => 'Model Town',
                    'feeder' => 'F1',
                    'start' => '2025-06-01T08:00:00+05:00',
                    'end' => '2025-06-01T12:00:00+05:00',
                    'type' => 'scheduled',
                    'confidence' => 0.6,
                ],
            ]),
        ];
        $store->appendManualEntry([
            'area' => 'Model Town',
            'feeder' => 'F1',
            'start' => '2025-06-01T08:00:00+05:00',
            'end' => '2025-06-01T12:00:00+05:00',
            'type' => 'maintenance',
            'confidence' => 0.95,
        ]);
        $scraper = new LescoScraper($store, $factory);
        $items = $scraper->fetch();
        assertCount(1, $items);
        assertSame('maintenance', $items[0]['type']);
        assertSame('Lahore Central', $items[0]['division']);
        $report = $scraper->lastReport();
        assertTrue(isset($report['sources']['official']));
        assertTrue(isset($report['sources']['manual']));
        $probe = $scraper->probe();
        assertTrue($probe['ok']);
        assertCount(1, $probe['sample']);
    });
});

test('LescoScraper reports source failure', function (): void {
    withTempDir(function (string $dir): void {
        $store = new Store($dir);
        $cfg = $store->readConfig();
        $cfg['sources']['official']['enabled'] = true;
        $cfg['sources']['manual']['enabled'] = false;
        $cfg['sources']['ccms']['enabled'] = false;
        $cfg['sources']['pdf']['enabled'] = false;
        $store->writeConfig($cfg);
        $scraper = new LescoScraper($store, ['official' => fn () => new ThrowingSource()]);
        $items = $scraper->fetch();
        assertCount(0, $items);
        $report = $scraper->lastReport();
        assertTrue(($report['sources']['official']['ok'] ?? null) === false);
    });
});

$failures = 0;
foreach ($tests as $test) {
    try {
        $test['fn']();
        echo '.';
    } catch (Throwable $e) {
        $failures++;
        echo 'F';
        $messages[] = ['name' => $test['name'], 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
    }
}

echo "\n\n";
if (!empty($messages)) {
    foreach ($messages as $failure) {
        echo $failure['name'] . "\n";
        echo $failure['message'] . "\n";
        echo $failure['trace'] . "\n\n";
    }
}

echo sprintf("%d test(s), %d failure(s)\n", count($tests), $failures);
exit($failures > 0 ? 1 : 0);
