<?php

declare(strict_types=1);

namespace PhpMcp\Protocol;

class JsonRpcResponse
{
    // Standard JSON-RPC 2.0 Error Codes
    public const PARSE_ERROR      = -32700;
    public const INVALID_REQUEST  = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS   = -32602;
    public const INTERNAL_ERROR   = -32603;

    public function __construct(
        public readonly mixed $id,
        public readonly mixed $result = null,
        public readonly ?array $error = null,
        public readonly string $jsonrpc = '2.0'
    ) {}

    public static function success(mixed $id, mixed $result): self
    {
        return new self(id: $id, result: $result);
    }

    public static function error(mixed $id, int $code, string $message, mixed $data = null): self
    {
        $err = [
            'code'    => $code,
            'message' => $message,
        ];
        if ($data !== null) {
            $err['data'] = $data;
        }

        return new self(id: $id, error: $err);
    }

    public function toArray(): array
    {
        $payload = [
            'jsonrpc' => $this->jsonrpc,
            'id'      => $this->id,
        ];

        if ($this->error !== null) {
            $payload['error'] = $this->error;
        } else {
            $payload['result'] = $this->result;
        }

        return $payload;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
