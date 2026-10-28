<?php
/**
 * MCP Server-Sent Events (SSE) Web Transport Example
 * For web-based AI clients and streaming agent interfaces.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Server.php';
require_once __DIR__ . '/../src/Tool.php';
require_once __DIR__ . '/../src/Resource.php';
require_once __DIR__ . '/../src/Prompt.php';
require_once __DIR__ . '/../src/Protocol/ProtocolVersion.php';
require_once __DIR__ . '/../src/Protocol/JsonRpcRequest.php';
require_once __DIR__ . '/../src/Protocol/JsonRpcResponse.php';
require_once __DIR__ . '/../src/Transport/TransportInterface.php';
require_once __DIR__ . '/../src/Transport/SseTransport.php';

use PhpMcp\Server;
use PhpMcp\Tool;
use PhpMcp\Transport\SseTransport;

$server = new Server('StreamingMcpServer', '1.0.0');

$server->addTool(
    Tool::create('ping')
        ->description('Check server liveness')
        ->handler(fn() => ['pong' => true, 'time' => microtime(true)])
);

// Listen over SSE transport when executed directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $transport = new SseTransport(postEndpoint: '/mcp/messages');
    $server->listen($transport);
}

return $server;
