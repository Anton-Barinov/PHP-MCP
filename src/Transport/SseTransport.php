<?php

declare(strict_types=1);

namespace PhpMcp\Transport;

use PhpMcp\Protocol\JsonRpcRequest;
use PhpMcp\Protocol\JsonRpcResponse;
use Throwable;

class SseTransport implements TransportInterface
{
    private string $postEndpoint;

    public function __construct(string $postEndpoint = '/mcp/messages')
    {
        $this->postEndpoint = $postEndpoint;
    }

    public function listen(callable $handler): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // 1. Initial SSE Connection (GET)
        if ($method === 'GET') {
            $this->startSseStream();
            return;
        }

        // 2. Client Message Delivery (POST)
        if ($method === 'POST') {
            $raw = file_get_contents('php://input');
            if ($raw === false || trim($raw) === '') {
                http_response_code(400);
                return;
            }

            try {
                $request = JsonRpcRequest::fromJson($raw);
                $response = call_user_func($handler, $request);

                if ($response instanceof JsonRpcResponse && !$request->isNotification()) {
                    http_response_code(200);
                    header('Content-Type: application/json');
                    echo $response->toJson();
                } else {
                    http_response_code(202);
                }
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['error' => $e->getMessage()]);
            }
            return;
        }

        http_response_code(405);
    }

    public function send(JsonRpcResponse $response): void
    {
        echo "event: message\n";
        echo "data: " . $response->toJson() . "\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function startSseStream(): void
    {
        $sessionId = bin2hex(random_bytes(16));
        $endpointUrl = $this->postEndpoint . '?sessionId=' . $sessionId;

        if (!headers_sent()) {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }

        echo "event: endpoint\n";
        echo "data: " . $endpointUrl . "\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
