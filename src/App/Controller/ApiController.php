<?php
namespace SHUTDOWN\App\Controller;

use SHUTDOWN\App\Application;
use SHUTDOWN\App\Http\HttpException;
use SHUTDOWN\App\Http\Request;
use SHUTDOWN\App\Http\Response;
use SHUTDOWN\Util\Exporter;

class ApiController
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function getConfig(Request $request): Response
    {
        return Response::json(['ok' => true, 'config' => $this->app->config()]);
    }

    public function updateConfig(Request $request): Response
    {
        $payload = $request->jsonBody();
        $this->app->updateConfig($payload);
        return Response::json(['ok' => true, 'saved' => true]);
    }

    public function ingest(Request $request): Response
    {
        $scraper = $this->app->scraper();
        $items = $scraper->fetch();
        $this->app->store()->writeSchedule($items);
        $report = $scraper->lastReport();
        return Response::json([
            'ok' => true,
            'count' => count($items),
            'updatedAt' => $report['generatedAt'] ?? gmdate('c'),
            'report' => $report,
        ]);
    }

    public function probe(Request $request): Response
    {
        $probe = $this->app->scraper()->probe();
        return Response::json($probe);
    }

    public function divisions(Request $request): Response
    {
        return Response::json(['ok' => true, 'divisions' => $this->app->store()->listDivisions()]);
    }

    public function schedule(Request $request): Response
    {
        $store = $this->app->store();
        $data = $store->readSchedule();
        $items = $data['items'] ?? [];
        $filtered = $store->filterItems($items, $request->queryAll());
        return Response::json([
            'ok' => true,
            'updatedAt' => $data['updatedAt'] ?? null,
            'total' => count($items),
            'count' => count($filtered),
            'items' => $filtered,
        ]);
    }

    public function export(Request $request): Response
    {
        $store = $this->app->store();
        $data = $store->readSchedule();
        $items = $store->filterItems($data['items'] ?? [], $request->queryAll());
        $format = strtolower((string) ($request->query('format', 'csv')));
        $stamp = date('Ymd-His');
        switch ($format) {
            case 'csv':
                $body = Exporter::toCsv($items);
                $contentType = 'text/csv; charset=utf-8';
                $ext = 'csv';
                break;
            case 'ics':
                $body = Exporter::toIcs($items);
                $contentType = 'text/calendar; charset=utf-8';
                $ext = 'ics';
                break;
            case 'json':
                $body = Exporter::toJson($items);
                $contentType = 'application/json; charset=utf-8';
                $ext = 'json';
                break;
            default:
                throw new HttpException(400, 'Unsupported export format');
        }
        $filename = sprintf('shutdown-export-%s.%s', $stamp, $ext);
        return Response::raw($body, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    public function backup(Request $request): Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'shutdown-backup-');
        if ($tmp === false) {
            throw new HttpException(500, 'Unable to create temp file');
        }
        $zipPath = $tmp . '.zip';
        if (!@rename($tmp, $zipPath)) {
            @unlink($tmp);
            throw new HttpException(500, 'Unable to prepare temp file');
        }
        $this->app->store()->backupZip($zipPath);
        $contents = file_get_contents($zipPath);
        $size = @filesize($zipPath);
        @unlink($zipPath);
        if ($contents === false) {
            throw new HttpException(500, 'Unable to read backup file');
        }
        $headers = [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => sprintf('attachment; filename="shutdown-storage-%s.zip"', date('Ymd-His')),
        ];
        if ($size !== false) {
            $headers['Content-Length'] = (string) $size;
        }
        return Response::raw($contents, 200, $headers);
    }

    public function addManual(Request $request): Response
    {
        $payload = $request->jsonBody();
        foreach (['area', 'feeder', 'start'] as $required) {
            if (empty($payload[$required])) {
                throw new HttpException(400, 'Missing field: ' . $required);
            }
        }
        $entry = $this->app->store()->appendManualEntry($payload);
        return Response::json(['ok' => true, 'entry' => $entry]);
    }

    public function history(Request $request): Response
    {
        $day = (string) $request->query('day', date('Y-m-d'));
        $items = $this->app->store()->readHistory($day);
        return Response::json(['ok' => true, 'day' => $day, 'count' => count($items), 'items' => $items]);
    }

    public function changelog(Request $request): Response
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, $limit);
        $entries = $this->app->store()->readChangelog($limit);
        return Response::json(['ok' => true, 'entries' => $entries]);
    }

    public function forecast(Request $request): Response
    {
        $store = $this->app->store();
        $data = $store->readSchedule();
        $forecast = $this->app->analytics()->forecast($data['items'] ?? []);
        $forecast['scheduleUpdatedAt'] = $data['updatedAt'] ?? null;
        return Response::json($forecast);
    }
}
