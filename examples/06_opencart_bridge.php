<?php
/**
 * OpenCart / E-Commerce MCP Bridge Example
 * Demonstrates bridging CMS models (orders, products, customers) to AI Agents.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use PhpMcp\Server;
use PhpMcp\Tool;
use PhpMcp\Resource;

$server = new Server('OpenCart-AI-Agent-Bridge', '1.0.0');

// Mock OpenCart DB / Model interaction
$server->addTool(
    Tool::create('get_order_details')
        ->description('Retrieve order items, totals, and shipping status by Order ID')
        ->param('order_id', 'number', 'The OpenCart order identifier', required: true)
        ->handler(function (array $args) {
            $id = (int)$args['order_id'];
            return [
                'order_id'       => $id,
                'customer'       => 'Dmitry Ivanov',
                'status'         => 'Processing',
                'total'          => '$184.50',
                'items'          => [
                    ['product' => 'Apple AirPods Pro', 'quantity' => 1, 'price' => '$179.00'],
                    ['product' => 'Silicone Case', 'quantity' => 1, 'price' => '$5.50'],
                ],
                'shipping_method'=> 'Express Courier Delivery',
            ];
        })
);

$server->addTool(
    Tool::create('update_order_status')
        ->description('Update order status and optionally notify customer')
        ->param('order_id', 'number', 'Order ID', required: true)
        ->param('status', 'string', 'New status string', required: true, enum: ['Pending', 'Processing', 'Shipped', 'Complete', 'Canceled'])
        ->param('notify', 'boolean', 'Whether to email customer notification', required: false)
        ->handler(function (array $args) {
            return [
                'success'  => true,
                'order_id' => $args['order_id'],
                'status'   => $args['status'],
                'notified' => $args['notify'] ?? false,
            ];
        })
);

$server->addResource(
    Resource::create('opencart://stats/today', 'Today Store Analytics')
        ->description('Real-time order count and revenue for today')
        ->mimeType('application/json')
        ->reader(fn() => [
            'orders_today'  => 38,
            'revenue_today' => '$5,420.00',
            'top_category'  => 'Electronics & Gadgets',
        ])
);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $server->listen();
}

return $server;
