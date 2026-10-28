<?php

declare(strict_types=1);

namespace PhpMcp;

class Prompt
{
    private string $name;
    private string $description = '';
    private array $arguments = [];
    /** @var callable|null */
    private $generator = null;

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

    public function arg(string $name, string $description = '', bool $required = false): self
    {
        $this->arguments[] = [
            'name'        => $name,
            'description' => $description,
            'required'    => $required,
        ];
        return $this;
    }

    public function generator(callable $generator): self
    {
        $this->generator = $generator;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function toMcpDefinition(): array
    {
        return [
            'name'        => $this->name,
            'description' => $this->description,
            'arguments'   => $this->arguments,
        ];
    }

    public function get(array $args): array
    {
        if ($this->generator === null) {
            return [
                'description' => $this->description,
                'messages'    => [],
            ];
        }

        $res = call_user_func($this->generator, $args);
        if (is_string($res)) {
            return [
                'description' => $this->description,
                'messages'    => [
                    [
                        'role'    => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => $res,
                        ],
                    ],
                ],
            ];
        }

        return $res;
    }
}
