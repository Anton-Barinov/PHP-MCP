<?php

declare(strict_types=1);

namespace PhpMcp;

class ResourceTemplate
{
    private string $uriTemplate;
    private string $name;
    private string $description = '';
    private string $mimeType = 'text/plain';

    public function __construct(string $uriTemplate, string $name)
    {
        $this->uriTemplate = $uriTemplate;
        $this->name = $name;
    }

    public static function create(string $uriTemplate, string $name): self
    {
        return new self($uriTemplate, $name);
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function mimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getUriTemplate(): string
    {
        return $this->uriTemplate;
    }

    public function toMcpDefinition(): array
    {
        return [
            'uriTemplate' => $this->uriTemplate,
            'name'        => $this->name,
            'description' => $this->description,
            'mimeType'    => $this->mimeType,
        ];
    }
}
