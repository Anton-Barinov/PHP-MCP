<?php

declare(strict_types=1);

namespace PhpMcp\Laravel;

use PhpMcp\Server;

/**
 * Optional Laravel Service Provider
 */
class McpServiceProvider
{
    public function register(): void
    {
        if (function_exists('app')) {
            app()->singleton('php-mcp', function () {
                $name = config('app.name', 'Laravel-MCP-Server');
                return new Server($name, '1.0.0');
            });
        }
    }

    public function boot(): void
    {
        // Routes can be loaded by developer via Mcp::listen()
    }
}
