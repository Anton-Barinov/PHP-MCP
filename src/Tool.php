<?php

declare(strict_types=1);

namespace PhpMcp;

use Closure;
use InvalidArgumentException;
use Throwable;

class Tool
{
    private string $name;
    private string $description = '';
    private array $properties = [];
    private array $required = [];
    /** @var callable|null */
    private $handler = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function create(string $name): self
    {
        return new self($name);
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Add a parameter to the tool's JSON schema input
     */
    public function param(
        string $name,
        string $type = 'string',
        string $description = '',
        bool $required = false,
        ?array $enum = null
    ): self {
        $schema = [
            'type'        => $type,
            'description' => $description,
        ];

        if ($enum !== null) {
            $schema['enum'] = $enum;
        }

        $this->properties[$name] = $schema;

        if ($required && !in_array($name, $this->required, true)) {
            $this->required[] = $name;
        }

        return $this;
    }

    /**
     * Define the callback handler for tool execution
     */
    public function handler(callable $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Formats according to MCP Model Context Protocol tools/list specification
     */
    public function toMcpDefinition(): array
    {
        $schema = [
            'type'       => 'object',
            'properties' => (object)$this->properties,
        ];

        if (!empty($this->required)) {
            $schema['required'] = $this->required;
        }

        return [
            'name'        => $this->name,
            'description' => $this->description,
            'inputSchema' => $schema,
        ];
    }

    /**
     * Executes the tool with provided arguments
     */
    public function execute(array $arguments): array
    {
        if ($this->handler === null) {
            throw new InvalidArgumentException("Tool '{$this->name}' has no execution handler defined.");
        }

        // Validate required parameters according to inputSchema
        foreach ($this->required as $reqField) {
            if (!array_key_exists($reqField, $arguments)) {
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Validation Error: Missing required parameter '{$reqField}' for tool '{$this->name}'.",
                        ],
                    ],
                    'isError' => true,
                ];
            }
        }

        // Protect STDOUT against stray echo / var_dump / notices from user tool handlers
        ob_start();
        $strayOutput = '';
        try {
            $result = call_user_func($this->handler, $arguments);
            $strayOutput = (string)ob_get_clean();
            $isError = false;
        } catch (Throwable $e) {
            $strayOutput = (string)ob_get_clean();
            if (!empty($strayOutput) && defined('STDERR')) {
                @fwrite(STDERR, "[PHP-MCP Tool Output] " . $strayOutput . "\n");
            }
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Error: ' . $e->getMessage(),
                    ],
                ],
                'isError' => true,
            ];
        }

        if (!empty($strayOutput) && defined('STDERR')) {
            @fwrite(STDERR, "[PHP-MCP Tool Output] " . $strayOutput . "\n");
        }

        // Standard MCP tool execution result format: { content: [{ type: "text", text: "..." }] }
        if (is_array($result) && isset($result['content']) && is_array($result['content'])) {
            return $result;
        }

        if (is_string($result)) {
            $text = $result;
        } else {
            $text = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
            'isError' => false,
        ];
    }
}
