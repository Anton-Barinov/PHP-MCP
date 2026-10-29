<?php

declare(strict_types=1);

namespace PhpMcp\Tests;

use PhpMcp\Server;
use PhpMcp\Transport\HttpTransport;

require_once __DIR__ . '/../src/autoload.php';

echo "Running HttpTransportTest...\n";

$server = new Server('test-http', '1.0.0');
$server->tool(
    name: 'greet',
    handler: fn(array $args) => "Hello, " . $args['name'] . "!",
    description: 'Greet someone',
    parameters: [
        'name' => ['type' => 'string', 'required' => true]
    ]
);

// 1. Direct handleRaw with ping
$transport = new HttpTransport();
$pingPayload = json_encode([
    'jsonrpc' => '2.0',
    'id' => 'req-1',
    'method' => 'ping',
    'params' => (object)[]
]);

$response = $transport->handleRaw($server, $pingPayload);
$decoded = json_decode($response, true);
assert($decoded['id'] === 'req-1');
assert(isset($decoded['result']));
echo "  ✓ handleRaw ping passed\n";

// 2. Direct handleRaw with tool call
$toolPayload = json_encode([
    'jsonrpc' => '2.0',
    'id' => 'req-2',
    'method' => 'tools/call',
    'params' => [
        'name' => 'greet',
        'arguments' => ['name' => 'Claude']
    ]
]);

$response = $transport->handleRaw($server, $toolPayload);
$decoded = json_decode($response, true);
assert($decoded['id'] === 'req-2');
assert($decoded['result']['content'][0]['text'] === 'Hello, Claude!');
echo "  ✓ handleRaw tool call passed\n";

// 3. Batch request with notification
$batchPayload = json_encode([
    ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
    ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['percent' => 50]], // Notification (no id)
    ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']
]);

$response = $transport->handleRaw($server, $batchPayload);
$batchDecoded = json_decode($response, true);
assert(is_array($batchDecoded) && count($batchDecoded) === 2, 'Batch should only return responses for non-notifications');
assert($batchDecoded[0]['id'] === 1);
assert($batchDecoded[1]['id'] === 2);
assert($batchDecoded[1]['result']['tools'][0]['name'] === 'greet');
echo "  ✓ Batch JSON-RPC with notification suppression passed\n";

// 4. Test Parse Error handling
$invalidJsonRes = $server->handleRaw("{invalid-json");
$errDecoded = json_decode($invalidJsonRes, true);
assert($errDecoded['error']['code'] === \PhpMcp\Protocol\JsonRpcResponse::PARSE_ERROR);
echo "  ✓ Parse Error returned correct code -32700\n";

// 5. Test Invalid Request handling (missing method)
$invalidReqRes = $server->handleRaw(json_encode(['jsonrpc' => '2.0', 'id' => 99]));
$invDecoded = json_decode($invalidReqRes, true);
assert($invDecoded['id'] === 99);
assert($invDecoded['error']['code'] === \PhpMcp\Protocol\JsonRpcResponse::INVALID_REQUEST);
echo "  ✓ Invalid Request returned code -32600 with matching id\n";

echo "HttpTransportTest passed successfully!\n";
