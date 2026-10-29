<?php

declare(strict_types=1);

namespace PhpMcp\Transport;

use PhpMcp\Protocol\JsonRpcRequest;
use PhpMcp\Protocol\JsonRpcResponse;
use Throwable;

class HttpTransport implements TransportInterface
{
    private bool $enableCors;

    public function __construct(bool $enableCors = true)
    {
        $this->enableCors = $enableCors;
    }

    public function listen(callable $handler): void
    {
        if ($this->enableCors) {
            $this->sendCorsHeaders();
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit(0);
        }

        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed. MCP HTTP transport requires POST.']);
            return;
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            $err = JsonRpcResponse::error(null, JsonRpcResponse::PARSE_ERROR, 'Empty request body');
            $this->send($err);
            return;
        }

        $responseStr = $this->handleRaw($handler, $rawBody);
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo $responseStr;
    }

    /**
     * Process raw JSON payload in memory (ideal for Laravel/Symfony/testing)
     */
    public function handleRaw(callable|object $handler, string $rawBody): string
    {
        if ($handler instanceof \PhpMcp\Server) {
            return (string)($handler->handleRaw($rawBody) ?? '');
        }

        $callback = is_callable($handler)
            ? $handler
            : fn(JsonRpcRequest $req) => $handler->handleRequest($req);

        $decoded = json_decode($rawBody, true);
        if ($decoded === null && trim($rawBody) !== 'null') {
            return JsonRpcResponse::error(null, JsonRpcResponse::PARSE_ERROR, 'Invalid JSON payload')->toJson();
        }

        // Support JSON-RPC 2.0 Batch request
        if (is_array($decoded) && array_is_list($decoded)) {
            $responses = [];
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    $responses[] = JsonRpcResponse::error(null, JsonRpcResponse::INVALID_REQUEST, 'Batch item must be an object')->toArray();
                    continue;
                }
                $req = JsonRpcRequest::fromArray($item);
                $res = call_user_func($callback, $req);
                if ($res instanceof JsonRpcResponse && !$req->isNotification()) {
                    $responses[] = $res->toArray();
                }
            }
            return json_encode($responses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Single request
        try {
            $request = JsonRpcRequest::fromArray(is_array($decoded) ? $decoded : []);
            $response = call_user_func($callback, $request);

            if ($response instanceof JsonRpcResponse) {
                return $response->toJson();
            }

            return '';
        } catch (Throwable $e) {
            return JsonRpcResponse::error(null, JsonRpcResponse::PARSE_ERROR, $e->getMessage())->toJson();
        }
    }

    public function send(JsonRpcResponse $response): void
    {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo $response->toJson();
    }

    private function sendCorsHeaders(): void
    {
        if (!headers_sent()) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        }
    }
}
