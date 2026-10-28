<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../src/Server.php';
require_once __DIR__ . '/../src/Tool.php';
require_once __DIR__ . '/../src/Protocol/ProtocolVersion.php';
require_once __DIR__ . '/../src/Protocol/JsonRpcRequest.php';
require_once __DIR__ . '/../src/Protocol/JsonRpcResponse.php';
require_once __DIR__ . '/../src/Transport/TransportInterface.php';
require_once __DIR__ . '/../src/Transport/StdioTransport.php';

use PhpMcp\Server;
use PhpMcp\Tool;
use PhpMcp\Transport\StdioTransport;

echo "=== Running PHP-MCP Stdio Transport Tests ===\n\n";

$server = new Server('StdioTestServer', '1.0.0');
$server->addTool(
    Tool::create('ping')
        ->description('Ping test')
        ->handler(fn() => 'pong')
);

// Prepare memory streams
$input = fopen('php://memory', 'r+');
$output = fopen('php://memory', 'r+');

// Send JSON-RPC initialize request
$reqLine = json_encode(['jsonrpc' => '2.0', 'id' => 'stdio_1', 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']]) . "\n";
fwrite($input, $reqLine);
rewind($input);

$transport = new StdioTransport($input, $output);
$transport->listen(fn($req) => $server->handle($req));

rewind($output);
$rawResponse = stream_get_contents($output);

$response = json_decode($rawResponse, true);

if (isset($response['result']['serverInfo']['name']) && $response['result']['serverInfo']['name'] === 'StdioTestServer') {
    echo "  [PASS] StdioTransport successfully parsed input and streamed response.\n";
} else {
    echo "  [FAIL] StdioTransport response invalid! Raw: {$rawResponse}\n";
    exit(1);
}

fclose($input);
fclose($output);

echo "\nALL STDIO TESTS PASSED SUCCESSFULLY!\n";
