<?php
/**
 * Ready-to-use Server for Claude Desktop & Cursor
 * 
 * Add to your Claude Desktop config (claude_desktop_config.json):
 * {
 *   "mcpServers": {
 *     "system-tools": {
 *       "command": "php",
 *       "args": ["/absolute/path/to/examples/02_claude_desktop_server.php"]
 *     }
 *   }
 * }
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use PhpMcp\Server;
use PhpMcp\Tool;
use PhpMcp\Resource;
use PhpMcp\Prompt;

$server = new Server('DesktopHelper', '1.0.0');

// 1. Tool: System Info
$server->addTool(
    Tool::create('get_system_info')
        ->description('Get current PHP version, OS, memory usage and server time')
        ->handler(fn() => [
            'os'          => PHP_OS_FAMILY,
            'php_version' => PHP_VERSION,
            'memory_used' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
            'server_time' => date('Y-m-d H:i:s T'),
        ])
);

// 2. Tool: Math Calculator
$server->addTool(
    Tool::create('calculate')
        ->description('Perform basic arithmetic calculation')
        ->param('a', 'number', 'First operand', required: true)
        ->param('b', 'number', 'Second operand', required: true)
        ->param('op', 'string', 'Operation (+, -, *, /)', required: true, enum: ['+', '-', '*', '/'])
        ->handler(function(array $args) {
            $a = (float)$args['a'];
            $b = (float)$args['b'];
            $op = (string)$args['op'];

            return match ($op) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
                '/' => $b != 0 ? $a / $b : 'Division by zero error',
                default => 'Unknown operator',
            };
        })
);

// 3. Resource: System Environment
$server->addResource(
    Resource::create('env://runtime', 'PHP Runtime Environment')
        ->description('Summary of active PHP runtime extensions and configuration')
        ->mimeType('application/json')
        ->reader(fn() => [
            'sapi'       => php_sapi_name(),
            'extensions' => get_loaded_extensions(),
        ])
);

// 4. Prompt: Code Review
$server->addPrompt(
    Prompt::create('review_php_code')
        ->description('Prompt to perform a strict security & PSR-12 code review')
        ->arg('code', 'The PHP code snippet to review', required: true)
        ->generator(fn($args) => "Please review the following PHP code for security vulnerabilities, type safety, and PSR-12 compliance:\n\n```php\n{$args['code']}\n```")
);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $server->listen();
}

return $server;
