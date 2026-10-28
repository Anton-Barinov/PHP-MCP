<?php
/**
 * PHP 8 Attributes Example for PHP-MCP
 * Turn any existing service or controller class into an MCP server automatically.
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
require_once __DIR__ . '/../src/Transport/StdioTransport.php';
require_once __DIR__ . '/../src/Attributes/McpTool.php';
require_once __DIR__ . '/../src/Attributes/McpResource.php';
require_once __DIR__ . '/../src/Attributes/McpPrompt.php';

use PhpMcp\Server;
use PhpMcp\Attributes\McpTool;
use PhpMcp\Attributes\McpResource;
use PhpMcp\Attributes\McpPrompt;

class StoreService
{
    #[McpTool(name: 'find_customer', description: 'Search customer record by email')]
    public function findCustomer(string $email): array
    {
        return [
            'email'    => $email,
            'name'     => 'Alex Mercer',
            'orders'   => 5,
            'status'   => 'VIP',
        ];
    }

    #[McpTool(description: 'Calculate order discount rate')]
    public function getDiscount(int $orderCount, bool $isVip = false): float
    {
        if ($isVip) {
            return 0.20;
        }
        return $orderCount > 3 ? 0.10 : 0.05;
    }

    #[McpResource(uri: 'store://policy', description: 'Store return & refund policy')]
    public function getPolicy(): string
    {
        return "Returns accepted within 30 days of purchase in original condition.";
    }

    #[McpPrompt(name: 'summarize_customer', description: 'Generate customer loyalty summary prompt')]
    public function summarizePrompt(array $args): string
    {
        return "Please write a friendly loyalty review for customer {$args['email']}.";
    }
}

$server = new Server('AttributeStoreMCP', '1.0.0');
$server->registerClass(new StoreService());

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $server->listen();
}

return $server;
