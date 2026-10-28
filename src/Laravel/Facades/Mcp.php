<?php

declare(strict_types=1);

namespace PhpMcp\Laravel\Facades;

use PhpMcp\Server;

/**
 * Optional Laravel Facade
 * 
 * @method static Server addTool(\PhpMcp\Tool $tool)
 * @method static Server addResource(\PhpMcp\Resource $resource)
 * @method static Server addPrompt(\PhpMcp\Prompt $prompt)
 * @method static Server addDatabase(\PDO $pdo, bool $readOnly = true)
 * @method static Server registerClass(object|string $instance)
 * @method static void listen(?\PhpMcp\Transport\TransportInterface $transport = null)
 */
class Mcp
{
    protected static function getFacadeAccessor(): string
    {
        return 'php-mcp';
    }

    public static function __callStatic(string $method, array $args): mixed
    {
        if (function_exists('app') && app()->bound('php-mcp')) {
            $instance = app('php-mcp');
            return call_user_func_array([$instance, $method], $args);
        }

        static $fallback = null;
        if ($fallback === null) {
            $fallback = new Server();
        }
        return call_user_func_array([$fallback, $method], $args);
    }
}
