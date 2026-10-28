<?php

declare(strict_types=1);

namespace PhpMcp\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class McpResource
{
    public function __construct(
        public readonly string $uri,
        public readonly ?string $name = null,
        public readonly string $description = '',
        public readonly string $mimeType = 'text/plain'
    ) {}
}
