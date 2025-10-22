<?php
namespace SHUTDOWN\App\Http;

class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = [];

    public function add(string $method, string $route, callable $handler): self
    {
        $method = strtoupper($method);
        $route = $this->normalizeRoute($route);
        if (!isset($this->routes[$route])) {
            $this->routes[$route] = [];
        }
        $this->routes[$route][$method] = $handler;
        return $this;
    }

    public function get(string $route, callable $handler): self
    {
        return $this->add('GET', $route, $handler);
    }

    public function post(string $route, callable $handler): self
    {
        return $this->add('POST', $route, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $route = $this->normalizeRoute($request->route());
        $method = $request->method();
        if (!isset($this->routes[$route])) {
            throw new HttpException(404, 'Unknown route');
        }
        if (!isset($this->routes[$route][$method])) {
            throw new HttpException(405, 'Method not allowed', ['allowed' => array_keys($this->routes[$route])]);
        }
        $handler = $this->routes[$route][$method];
        $response = $handler($request);
        if (!$response instanceof Response) {
            throw new \RuntimeException('Route handler must return a Response instance');
        }
        return $response;
    }

    private function normalizeRoute(string $route): string
    {
        $route = trim($route);
        return $route === '' ? 'schedule' : $route;
    }
}
