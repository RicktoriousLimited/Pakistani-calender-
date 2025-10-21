<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'SHUTDOWN\\') !== 0) {
        return;
    }
    $relative = substr($class, strlen('SHUTDOWN\\'));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
