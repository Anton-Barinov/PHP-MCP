<?php

declare(strict_types=1);

/**
 * PSR-4 Autoloader for PHP-MCP
 * Can be used standalone or via Composer's vendor/autoload.php.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'PhpMcp\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
