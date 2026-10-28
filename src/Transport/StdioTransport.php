<?php

declare(strict_types=1);

namespace PhpMcp\Transport;

use PhpMcp\Protocol\JsonRpcRequest;
use PhpMcp\Protocol\JsonRpcResponse;
use Throwable;

class StdioTransport implements TransportInterface
{
    /** @var resource|null */
    private $stdin = null;
    /** @var resource|null */
    private $stdout = null;

    public function __construct($stdin = null, $stdout = null)
    {
        $this->stdin = $stdin ?: fopen('php://stdin', 'r');
        $this->stdout = $stdout ?: fopen('php://stdout', 'w');
    }

    public function listen(callable $handler): void
    {
        while (!feof($this->stdin)) {
            $line = fgets($this->stdin);
            if ($line === false || trim($line) === '') {
                continue;
            }

            try {
                $request = JsonRpcRequest::fromJson($line);
                $response = call_user_func($handler, $request);

                if ($response instanceof JsonRpcResponse && !$request->isNotification()) {
                    $this->send($response);
                }
            } catch (Throwable $e) {
                $errResponse = JsonRpcResponse::error(null, JsonRpcResponse::PARSE_ERROR, $e->getMessage());
                $this->send($errResponse);
            }
        }
    }

    public function send(JsonRpcResponse $response): void
    {
        fwrite($this->stdout, $response->toJson() . "\n");
        fflush($this->stdout);
    }
}
