<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use SHUTDOWN\Scraper\LescoScraper;
use SHUTDOWN\Util\Analytics;
use SHUTDOWN\Util\Exporter;
use SHUTDOWN\Util\Store;

function jsonOut($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$storageDir = __DIR__ . '/storage';
$store = new Store($storageDir);
$scraper = new LescoScraper($store);
$route = $_GET['route'] ?? 'schedule';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($route) {
        case 'config':
            if ($method === 'GET') {
                jsonOut(['ok' => true, 'config' => $store->readConfig()]);
            }
            $raw = file_get_contents('php://input') ?: '';
            if ($raw === '') {
                jsonOut(['ok' => false, 'error' => 'Empty payload'], 400);
            }
            $payload = json_decode($raw, true);
            if ($payload === null && json_last_error() !== JSON_ERROR_NONE) {
                jsonOut(['ok' => false, 'error' => 'Invalid JSON', 'detail' => json_last_error_msg()], 400);
            }
            $store->writeConfig($payload ?? []);
            jsonOut(['ok' => true, 'saved' => true]);
            break;

        case 'ingest':
            $items = $scraper->fetch();
            $store->writeSchedule($items);
            $report = $scraper->lastReport();
            jsonOut([
                'ok' => true,
                'count' => count($items),
                'updatedAt' => $report['generatedAt'] ?? gmdate('c'),
                'report' => $report,
            ]);
            break;

        case 'probe':
            $probe = $scraper->probe();
            jsonOut($probe);
            break;

        case 'divisions':
            jsonOut(['ok' => true, 'divisions' => $store->listDivisions()]);
            break;

        case 'schedule':
            $data = $store->readSchedule();
            $items = $data['items'] ?? [];
            $filtered = $store->filterItems($items, $_GET);
            jsonOut([
                'ok' => true,
                'updatedAt' => $data['updatedAt'] ?? null,
                'total' => count($items),
                'count' => count($filtered),
                'items' => $filtered,
            ]);
            break;

        case 'export':
            $data = $store->readSchedule();
            $items = $store->filterItems($data['items'] ?? [], $_GET);
            $format = strtolower((string)($_GET['format'] ?? 'csv'));
            $stamp = date('Ymd-His');
            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=shutdown-export-{$stamp}.csv");
                echo Exporter::toCsv($items);
                exit;
            }
            if ($format === 'ics') {
                header('Content-Type: text/calendar; charset=utf-8');
                header("Content-Disposition: attachment; filename=shutdown-export-{$stamp}.ics");
                echo Exporter::toIcs($items);
                exit;
            }
            if ($format === 'json') {
                header('Content-Type: application/json; charset=utf-8');
                header("Content-Disposition: attachment; filename=shutdown-export-{$stamp}.json");
                echo Exporter::toJson($items);
                exit;
            }
            jsonOut(['ok' => false, 'error' => 'Unsupported export format'], 400);
            break;

        case 'backup':
            $tmp = tempnam(sys_get_temp_dir(), 'shutdown-backup-');
            if ($tmp === false) {
                jsonOut(['ok' => false, 'error' => 'Unable to create temp file'], 500);
            }
            $zipPath = $tmp . '.zip';
            rename($tmp, $zipPath);
            $store->backupZip($zipPath);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="shutdown-storage-' . date('Ymd-His') . '.zip"');
            header('Content-Length: ' . filesize($zipPath));
            readfile($zipPath);
            unlink($zipPath);
            exit;

        case 'addManual':
            if ($method !== 'POST') {
                jsonOut(['ok' => false, 'error' => 'POST required'], 405);
            }
            $raw = file_get_contents('php://input') ?: '';
            $payload = $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($payload)) {
                jsonOut(['ok' => false, 'error' => 'Invalid JSON payload'], 400);
            }
            foreach (['area', 'feeder', 'start'] as $required) {
                if (empty($payload[$required])) {
                    jsonOut(['ok' => false, 'error' => "Missing field: {$required}"], 400);
                }
            }
            $entry = $store->appendManualEntry($payload);
            jsonOut(['ok' => true, 'entry' => $entry]);
            break;

        case 'history':
            $day = $_GET['day'] ?? date('Y-m-d');
            $items = $store->readHistory($day);
            jsonOut(['ok' => true, 'day' => $day, 'count' => count($items), 'items' => $items]);
            break;

        case 'changelog':
            $limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 50;
            jsonOut(['ok' => true, 'entries' => $store->readChangelog($limit)]);
            break;

        case 'forecast':
            $data = $store->readSchedule();
            $cfg = $store->readConfig();
            $timezone = (string) ($cfg['timezone'] ?? 'Asia/Karachi');
            $analytics = new Analytics($timezone);
            $forecast = $analytics->forecast($data['items'] ?? []);
            $forecast['scheduleUpdatedAt'] = $data['updatedAt'] ?? null;
            jsonOut($forecast);
            break;

        default:
            jsonOut(['ok' => false, 'error' => 'Unknown route'], 404);
    }
} catch (Throwable $e) {
    jsonOut([
        'ok' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getFile() . ':' . $e->getLine(),
    ], 500);
}
