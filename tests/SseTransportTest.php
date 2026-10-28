<?php

declare(strict_types=1);

namespace PhpMcp\Tests;

use PhpMcp\Server;
use PhpMcp\Protocol\JsonRpcResponse;
use PhpMcp\Transport\SseTransport;

require_once __DIR__ . '/../src/autoload.php';

echo "Running SseTransportTest...\n";

$transport = new SseTransport('/mcp/messages');

// 1. Test SseTransport message formatting
$response = JsonRpcResponse::success('req-sse', ['status' => 'online']);
$formatted = $transport->formatMessage($response);

assert(str_contains($formatted, "event: message\n"), 'SSE event type should be message');
assert(str_contains($formatted, 'data: {"jsonrpc":"2.0","id":"req-sse"'), 'SSE data should contain serialized response');
echo "  ✓ SSE formatMessage verified\n";

// 2. Test SseTransport instance creation
assert($transport instanceof \PhpMcp\Transport\TransportInterface);
echo "  ✓ TransportInterface contract verified\n";

echo "SseTransportTest passed successfully!\n";
