<?php

declare(strict_types=1);

namespace PhpMcp\Transport;

use PhpMcp\Protocol\JsonRpcRequest;
use PhpMcp\Protocol\JsonRpcResponse;

interface TransportInterface
{
    /**
     * Start listening for incoming requests and delegate to message handler
     * @param callable(JsonRpcRequest): ?JsonRpcResponse $handler
     */
    public function listen(callable $handler): void;

    /**
     * Send a response back to the client
     */
    public function send(JsonRpcResponse $response): void;
}
