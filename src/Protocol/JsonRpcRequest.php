<?php

declare(strict_types=1);

namespace PhpMcp\Protocol;

use InvalidArgumentException;

class JsonRpcRequest
{
    public function __construct(
        public readonly string $method,
        public readonly mixed $id = null,
        public readonly array $params = [],
        public readonly string $jsonrpc = '2.0'
    ) {}

    public static function fromJson(string $raw): self
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON payload');
        }

        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        if (!isset($data['method']) || !is_string($data['method'])) {
            throw new InvalidArgumentException('Missing or invalid "method" property');
        }

        if (isset($data['jsonrpc']) && $data['jsonrpc'] !== '2.0') {
            throw new InvalidArgumentException('Invalid JSON-RPC protocol version: expected "2.0"');
        }

        $id = $data['id'] ?? null;
        $params = isset($data['params']) && is_array($data['params']) ? $data['params'] : [];
        $jsonrpc = (string)($data['jsonrpc'] ?? '2.0');

        return new self(
            method: $data['method'],
            id: $id,
            params: $params,
            jsonrpc: $jsonrpc
        );
    }

    public function isNotification(): bool
    {
        return $this->id === null;
    }
}
