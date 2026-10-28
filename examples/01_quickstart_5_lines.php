<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Server.php';
require_once __DIR__ . '/../src/Tool.php';
require_once __DIR__ . '/../src/Resource.php';
require_once __DIR__ . '/../src/Prompt.php';
require_once __DIR__ . '/../src/Protocol/ProtocolVersion.php';
require_once __DIR__ . '/../src/Protocol/JsonRpcRequest.php';
require_once __DIR__ . '/../src/Protocol/JsonRpcResponse.php';
require_once __DIR__ . '/../src/Transport/TransportInterface.php';
require_once __DIR__ . '/../src/Transport/StdioTransport.php';
require_once __DIR__ . '/../src/Transport/HttpTransport.php';

use PhpMcp\Server;
use PhpMcp\Tool;

$server = new Server('MyStoreMCP', '1.0.0');

$server->addTool(
    Tool::create('get_product_stock')
        ->description('Fetch real-time stock and pricing by product SKU')
        ->param('sku', 'string', 'Product SKU code', required: true)
        ->handler(fn(array $args) => [
            'sku'      => $args['sku'],
            'price'    => 1299.99,
            'in_stock' => true,
            'quantity' => 14,
        ])
);

// If executed via CLI, listen on Stdio (or return instance when included)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $server->listen();
}

return $server;
