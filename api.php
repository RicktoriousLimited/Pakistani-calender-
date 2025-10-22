<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use SHUTDOWN\App\Application;
use SHUTDOWN\App\Controller\ApiController;
use SHUTDOWN\App\Http\HttpException;
use SHUTDOWN\App\Http\Request;
use SHUTDOWN\App\Http\Response;
use SHUTDOWN\App\Http\Router;
use Throwable;

$app = new Application(__DIR__ . '/storage');
$controller = new ApiController($app);

$router = (new Router())
    ->get('config', [$controller, 'getConfig'])
    ->post('config', [$controller, 'updateConfig'])
    ->get('ingest', [$controller, 'ingest'])
    ->get('probe', [$controller, 'probe'])
    ->get('divisions', [$controller, 'divisions'])
    ->get('schedule', [$controller, 'schedule'])
    ->get('export', [$controller, 'export'])
    ->get('backup', [$controller, 'backup'])
    ->post('addManual', [$controller, 'addManual'])
    ->get('history', [$controller, 'history'])
    ->get('changelog', [$controller, 'changelog'])
    ->get('forecast', [$controller, 'forecast']);

$request = Request::fromGlobals();

try {
    $response = $router->dispatch($request);
} catch (HttpException $e) {
    $payload = array_merge(['ok' => false, 'error' => $e->getMessage()], $e->getPayload());
    $response = Response::json($payload, $e->getStatus());
} catch (Throwable $e) {
    $response = Response::json([
        'ok' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getFile() . ':' . $e->getLine(),
    ], 500);
}

$response->send();
