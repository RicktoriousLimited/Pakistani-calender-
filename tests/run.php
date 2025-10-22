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
use SHUTDOWN\Util\Analytics;
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

test('Analytics builds a seven day forecast', function (): void {
    $analytics = new Analytics('Asia/Karachi');
    $now = new DateTimeImmutable('2025-01-01T08:00:00+05:00');
    $items = [
        [
            'area' => 'Model Town',
            'division' => 'Lahore Central',
            'feeder' => 'F1',
            'start' => '2025-01-01T10:00:00+05:00',
            'end' => '2025-01-01T12:30:00+05:00',
            'type' => 'maintenance',
            'reason' => 'Tree trimming',
        ],
        [
            'area' => 'Johar Town',
            'division' => 'Lahore East',
            'feeder' => 'JT-09',
            'start' => '2025-01-02T09:00:00+05:00',
            'end' => '2025-01-02T11:00:00+05:00',
            'type' => 'forced',
        ],
        [
            'area' => 'Past Event',
            'start' => '2024-12-28T09:00:00+05:00',
            'end' => '2024-12-28T10:00:00+05:00',
        ],
        [
            'area' => 'Future Outside Window',
            'start' => '2025-01-10T09:00:00+05:00',
            'end' => '2025-01-10T10:00:00+05:00',
        ],
    ];

    $forecast = $analytics->forecast($items, 7, $now);

    assertTrue($forecast['ok']);
    assertSame(2, $forecast['totals']['count']);
    assertSame(1, $forecast['totals']['within24h']);
    assertCount(2, $forecast['daily']);
    assertSame('Lahore Central', $forecast['divisions'][0]['division']);
    assertSame('MAINTENANCE', $forecast['types'][0]['type']);
    assertSame('2025-01-01T10:00:00+05:00', $forecast['upcoming'][0]['start']);
    assertSame('Model Town', $forecast['longest'][0]['area']);
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

test('PdfBulletin parses tabular PDF bulletins', function (): void {
    $text = implode("\n", [
        'Serial',
        'Division',
        'Sub Division',
        'Feeder Name',
        'Shutdown Date',
        'Start Time',
        'End Time',
        'Remarks',
        '1',
        'Model Town',
        'Garden Town',
        'ALI PARK 16720',
        '22-10-2025',
        '08:00 AM',
        '12:00 PM',
        'Conductor stringing',
        '2',
        'Shalimar',
        'Shad Bagh',
        'SB-03',
        '22-10-2025',
        '09:00 AM',
        '13:00 PM',
        'Tree trimming',
    ]);

    $bulletin = new PdfBulletin('https://example.test/bulletin.pdf', fn () => 'PDF', [], fn () => $text);
    $items = $bulletin->fetch();

    assertCount(2, $items);
    assertSame('Model Town | Garden Town', $items[0]['area']);
    assertSame('ALI PARK 16720', $items[0]['feeder']);
    assertSame('Conductor stringing', $items[0]['reason']);
    assertTrue(strpos($items[0]['start'], '2025-10-22') === 0);
    assertTrue(strpos($items[0]['end'], '2025-10-22') === 0);
    assertSame('Tree trimming', $items[1]['reason']);
});

test('PdfBulletin handles tables with spaced shut down headers', function (): void {
    $text = implode("\n", [
        'Sr.',
        'Circle',
        'Division',
        'Sub Division',
        'Feeder Name & Code',
        'Shut Down Date',
        'Time From',
        'Time To',
        'Nature of Work',
        '1',
        'North Lahore Circle',
        'Shalimar Division',
        'Baghbanpura Sub Division',
        '11 KV Baghbanpura Feeder',
        '22-10-2025',
        '0800',
        '1400',
        'Pole replacement',
    ]);

    $bulletin = new PdfBulletin('https://example.test/bulletin.pdf', fn () => 'PDF', [], fn () => $text);
    $items = $bulletin->fetch();

    assertCount(1, $items);
    assertSame('North Lahore | Shalimar | Baghbanpura', $items[0]['area']);
    assertSame('11 KV Baghbanpura', $items[0]['feeder']);
    assertTrue(strpos($items[0]['start'], '2025-10-22T08:00') === 0);
    assertTrue(strpos($items[0]['end'], '2025-10-22T14:00') === 0);
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

test('PdfBulletin discovers PDFs with spaces in the path', function (): void {
    $requests = [];
    $html = '<a href="Notice_files/SDLS/Shutdown Schedule TBR.pdf">Download</a>';
    $pdfText = implode("\n", [
        'Area: Model Town',
        'Feeder: F-1',
        'Start: 22-10-2025 08:00',
        'End: 22-10-2025 12:00',
        'Reason: Maintenance',
    ]);

    $fetcher = function (string $url) use (&$requests, $html) {
        $requests[] = $url;
        if (str_ends_with($url, '.pdf')) {
            return '%PDF';
        }
        return $html;
    };

    $extractor = fn (string $binary): string => $pdfText;

    $bulletin = new PdfBulletin('https://example.test/tbr', $fetcher, [], $extractor);
    $items = $bulletin->fetch();

    assertCount(2, $requests);
    assertSame('https://example.test/Notice_files/SDLS/Shutdown%20Schedule%20TBR.pdf', $requests[1]);
    assertCount(1, $items);
    assertSame('Model Town', $items[0]['area']);
    assertSame('F-1', $items[0]['feeder']);
});

test('PdfBulletin discovers PDFs referenced via JavaScript download helpers', function (): void {
    $requests = [];
    $html = <<<HTML
<button onclick="return DownloadFile('Notice_files/SDLS/LESCO Shutdown Schedule 09.07.2024.pdf');">Download</button>
<script>
    const latest = "Notice_files/SDLS/LESCO Shutdown Schedule 09.07.2024.pdf";
</script>
HTML;
    $pdfText = implode("\n", [
        'Area: Model Town',
        'Feeder: F-9',
        'Start: 09-07-2024 08:00',
        'End: 09-07-2024 12:00',
        'Reason: JavaScript link',
    ]);

    $fetcher = function (string $url) use (&$requests, $html) {
        $requests[] = $url;
        if (str_contains($url, '.pdf')) {
            return '%PDF';
        }
        return $html;
    };

    $extractor = fn (string $binary): string => $pdfText;

    $bulletin = new PdfBulletin('https://example.test/LoadSheddingShutdownSchedule', $fetcher, [], $extractor);
    $items = $bulletin->fetch();

    assertTrue(in_array('https://example.test/Notice_files/SDLS/LESCO%20Shutdown%20Schedule%2009.07.2024.pdf', $requests, true));
    assertCount(1, $items);
    assertSame('Model Town', $items[0]['area']);
    assertSame('F-9', $items[0]['feeder']);
});

test('PdfBulletin discovers embedded PDFs without anchor tags', function (): void {
    $requests = [];
    $html = '<iframe src="/files/latest-bulletin.pdf"></iframe>' .
        '<object data="/files/object-bulletin.pdf"></object>';
    $pdfText = implode("\n", [
        'Area: Embedded Area',
        'Feeder: E-1',
        'Start: 22-10-2025 08:00',
        'End: 22-10-2025 09:00',
        'Reason: Embedded test',
    ]);

    $fetcher = function (string $url) use (&$requests, $html) {
        $requests[] = $url;
        if (str_contains($url, '.pdf')) {
            return '%PDF';
        }
        return $html;
    };

    $extractor = fn (string $binary): string => $pdfText;

    $bulletin = new PdfBulletin('https://example.test/discover', $fetcher, [], $extractor);
    $items = $bulletin->fetch();

    $pdfRequests = array_values(array_filter($requests, fn ($url) => str_contains($url, '.pdf')));
    assertTrue(!empty($pdfRequests), 'Expected at least one PDF request');
    assertCount(1, $items);
    assertSame('Embedded Area', $items[0]['area']);
    assertSame('E-1', $items[0]['feeder']);
});

test('Official scraper parses table', function (): void {
    $html = '<table><tr><td>Area 1</td><td>F-1</td><td>2025-04-01 08:00</td><td>2025-04-01 10:00</td><td>Maintenance</td></tr></table>';
    $official = new Official('https://example.test', fn (string $url) => $html);
    $items = $official->fetch();
    assertCount(1, $items);
    assertSame('Area 1', $items[0]['area']);
    assertSame('F-1', $items[0]['feeder']);
});

test('Official scraper falls back to discovery pages and merges date/time columns', function (): void {
    $html = '<table><thead><tr><th>Division</th><th>Sub Division</th><th>Feeder Name</th><th>Shutdown Date</th><th>Start Time</th><th>End Time</th><th>Remarks</th></tr></thead>' .
        '<tbody><tr><td>Model Town</td><td>Garden Town</td><td>MT-01</td><td>22-10-2025</td><td>08:00 AM</td><td>12:00 PM</td><td>Maintenance Work</td></tr></tbody></table>';

    $calls = [];
    $fetcher = function (string $url) use (&$calls, $html) {
        $calls[] = $url;
        if ($url === 'https://example.test/fallback') {
            return $html;
        }
        return '';
    };

    $official = new Official('https://example.test/primary', $fetcher, ['https://example.test/fallback']);
    $items = $official->fetch();

    assertCount(1, $items);
    assertSame('Model Town | Garden Town', $items[0]['area']);
    assertSame('MT-01', $items[0]['feeder']);
    assertSame('https://example.test/fallback', $items[0]['url']);
    assertTrue(strpos($items[0]['start'], '2025-10-22') === 0);
    assertTrue(strpos($items[0]['end'], '2025-10-22') === 0);
    assertTrue(in_array('https://example.test/fallback', $calls, true));
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
