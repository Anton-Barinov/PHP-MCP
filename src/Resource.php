<?php

declare(strict_types=1);

namespace PhpMcp;

use InvalidArgumentException;

class Resource
{
    private string $uri;
    private string $name;
    private string $description = '';
    private string $mimeType = 'text/plain';
    /** @var callable|null */
    private $reader = null;

    public function __construct(string $uri, string $name)
    {
        $this->uri = $uri;
        $this->name = $name;
    }

    public static function create(string $uri, string $name): self
    {
        return new self($uri, $name);
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

    public function reader(callable $reader): self
    {
        $this->reader = $reader;
        return $this;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function toMcpDefinition(): array
    {
        return [
            'uri'         => $this->uri,
            'name'        => $this->name,
            'description' => $this->description,
            'mimeType'    => $this->mimeType,
        ];
    }

    public function read(): array
    {
        if ($this->reader === null) {
            throw new InvalidArgumentException("Resource '{$this->uri}' has no reader defined.");
        }

        $content = call_user_func($this->reader, $this->uri);
        $text = is_string($content) ? $content : json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return [
            'contents' => [
                [
                    'uri'      => $this->uri,
                    'mimeType' => $this->mimeType,
                    'text'     => $text,
                ],
            ],
        ];
    }
}
