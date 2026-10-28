<?php

declare(strict_types=1);

namespace PhpMcp;

use PDO;
use ReflectionClass;
use ReflectionMethod;
use PhpMcp\Adapters\PdoAdapter;
use PhpMcp\Attributes\McpTool;
use PhpMcp\Attributes\McpResource;
use PhpMcp\Attributes\McpPrompt;
use PhpMcp\Protocol\JsonRpcRequest;
use PhpMcp\Protocol\JsonRpcResponse;
use PhpMcp\Protocol\ProtocolVersion;
use PhpMcp\Transport\TransportInterface;
use PhpMcp\Transport\StdioTransport;
use PhpMcp\Transport\HttpTransport;
use Throwable;

class Server
{
    private string $name;
    private string $version;
    private string $protocolVersion;

    /** @var array<string, Tool> */
    private array $tools = [];
    /** @var array<string, Resource> */
    private array $resources = [];
    /** @var array<string, Prompt> */
    private array $prompts = [];

    public function __construct(
        string $name = 'PHP-MCP-Server',
        string $version = '1.0.0',
        string $protocolVersion = ProtocolVersion::LATEST
    ) {
        $this->name = $name;
        $this->version = $version;
        $this->protocolVersion = $protocolVersion;
    }

    public static function create(string $name = 'PHP-MCP-Server', string $version = '1.0.0'): self
    {
        return new self($name, $version);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * @return array<string, Tool>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array<string, Resource>
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    /**
     * @return array<string, Prompt>
     */
    public function getPrompts(): array
    {
        return $this->prompts;
    }

    public function handleRequest(JsonRpcRequest $request): ?JsonRpcResponse
    {
        return $this->handle($request);
    }

    public function addTool(Tool $tool): self
    {
        $this->tools[$tool->getName()] = $tool;
        return $this;
    }

    /**
     * Fluent helper to define and register a tool in one line
     */
    public function tool(
        string $name,
        callable $handler,
        string $description = '',
        array $parameters = []
    ): self {
        $tool = Tool::create($name)->handler($handler);
        if ($description !== '') {
            $tool->description($description);
        }
        foreach ($parameters as $pname => $pdef) {
            $type = is_array($pdef) ? ($pdef['type'] ?? 'string') : 'string';
            $pdesc = is_array($pdef) ? ($pdef['description'] ?? '') : '';
            $req = is_array($pdef) ? ($pdef['required'] ?? false) : false;
            $tool->param($pname, $type, $pdesc, $req);
        }
        return $this->addTool($tool);
    }

    public function addResource(Resource $resource): self
    {
        $this->resources[$resource->getUri()] = $resource;
        return $this;
    }

    public function addPrompt(Prompt $prompt): self
    {
        $this->prompts[$prompt->getName()] = $prompt;
        return $this;
    }

    /**
     * Connects any PDO database and instantly registers schema & query tools
     */
    public function addDatabase(PDO $pdo, bool $readOnly = true, int $maxRows = 100): self
    {
        PdoAdapter::register($this, $pdo, $readOnly, $maxRows);
        return $this;
    }

    /**
     * Auto-discovers methods marked with #[McpTool], #[McpResource], #[McpPrompt]
     */
    public function registerClass(object|string $instance): self
    {
        $ref = new ReflectionClass($instance);
        $obj = is_string($instance) ? $ref->newInstance() : $instance;

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Check #[McpTool]
            $toolAttrs = $method->getAttributes(McpTool::class);
            if (!empty($toolAttrs)) {
                /** @var McpTool $attr */
                $attr = $toolAttrs[0]->newInstance();
                $toolName = $attr->name ?: $method->getName();

                $tool = Tool::create($toolName)
                    ->description($attr->description ?: "Executes {$method->getName()}");

                foreach ($method->getParameters() as $param) {
                    $type = $param->getType() ? (string)$param->getType() : 'string';
                    $tool->param(
                        $param->getName(),
                        $type === 'int' ? 'number' : ($type === 'bool' ? 'boolean' : 'string'),
                        "Parameter \${$param->getName()}",
                        !$param->isOptional()
                    );
                }

                $tool->handler(function (array $args) use ($obj, $method) {
                    $callArgs = [];
                    foreach ($method->getParameters() as $param) {
                        $pName = $param->getName();
                        if (array_key_exists($pName, $args)) {
                            $callArgs[] = $args[$pName];
                        } elseif ($param->isDefaultValueAvailable()) {
                            $callArgs[] = $param->getDefaultValue();
                        } else {
                            $callArgs[] = null;
                        }
                    }
                    return $method->invokeArgs($obj, $callArgs);
                });

                $this->addTool($tool);
            }

            // Check #[McpResource]
            $resAttrs = $method->getAttributes(McpResource::class);
            if (!empty($resAttrs)) {
                /** @var McpResource $attr */
                $attr = $resAttrs[0]->newInstance();
                $res = Resource::create($attr->uri, $attr->name ?: $method->getName())
                    ->description($attr->description)
                    ->mimeType($attr->mimeType)
                    ->reader(fn($uri) => $method->invoke($obj, $uri));
                $this->addResource($res);
            }

            // Check #[McpPrompt]
            $promptAttrs = $method->getAttributes(McpPrompt::class);
            if (!empty($promptAttrs)) {
                /** @var McpPrompt $attr */
                $attr = $promptAttrs[0]->newInstance();
                $prompt = Prompt::create($attr->name ?: $method->getName())
                    ->description($attr->description)
                    ->generator(fn($args) => $method->invoke($obj, $args));
                $this->addPrompt($prompt);
            }
        }

        return $this;
    }

    /**
     * Dispatch incoming JSON-RPC 2.0 requests
     */
    public function handle(JsonRpcRequest $request): ?JsonRpcResponse
    {
        try {
            switch ($request->method) {
                case 'initialize':
                    return $this->handleInitialize($request);

                case 'notifications/initialized':
                case 'initialized':
                    return null; // Notification acknowledged, no response needed

                case 'ping':
                    return JsonRpcResponse::success($request->id, new \stdClass());

                case 'tools/list':
                    return $this->handleToolsList($request);

                case 'tools/call':
                    return $this->handleToolsCall($request);

                case 'resources/list':
                    return $this->handleResourcesList($request);

                case 'resources/read':
                    return $this->handleResourcesRead($request);

                case 'prompts/list':
                    return $this->handlePromptsList($request);

                case 'prompts/get':
                    return $this->handlePromptsGet($request);

                default:
                    return JsonRpcResponse::error(
                        $request->id,
                        JsonRpcResponse::METHOD_NOT_FOUND,
                        "Unknown method '{$request->method}'"
                    );
            }
        } catch (Throwable $e) {
            return JsonRpcResponse::error(
                $request->id,
                JsonRpcResponse::INTERNAL_ERROR,
                $e->getMessage()
            );
        }
    }

    /**
     * Start listening for requests over selected or auto-detected transport
     */
    public function listen(?TransportInterface $transport = null): void
    {
        if ($transport === null) {
            // Auto-detect: if run via CLI, use Stdio; if via web server, use HTTP
            $transport = php_sapi_name() === 'cli' ? new StdioTransport() : new HttpTransport();
        }

        $transport->listen(fn(JsonRpcRequest $req) => $this->handle($req));
    }

    private function handleInitialize(JsonRpcRequest $request): JsonRpcResponse
    {
        $clientVersion = (string)($request->params['protocolVersion'] ?? ProtocolVersion::LATEST);
        $negotiated = ProtocolVersion::isSupported($clientVersion) ? $clientVersion : ProtocolVersion::LATEST;

        $capabilities = [
            'tools'     => new \stdClass(),
            'resources' => new \stdClass(),
            'prompts'   => new \stdClass(),
        ];

        return JsonRpcResponse::success($request->id, [
            'protocolVersion' => $negotiated,
            'capabilities'    => $capabilities,
            'serverInfo'      => [
                'name'    => $this->name,
                'version' => $this->version,
            ],
        ]);
    }

    private function handleToolsList(JsonRpcRequest $request): JsonRpcResponse
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = $tool->toMcpDefinition();
        }

        return JsonRpcResponse::success($request->id, [
            'tools' => $tools,
        ]);
    }

    private function handleToolsCall(JsonRpcRequest $request): JsonRpcResponse
    {
        $name = (string)($request->params['name'] ?? '');
        $arguments = isset($request->params['arguments']) && is_array($request->params['arguments'])
            ? $request->params['arguments']
            : [];

        if (!isset($this->tools[$name])) {
            return JsonRpcResponse::error(
                $request->id,
                JsonRpcResponse::INVALID_PARAMS,
                "Tool '{$name}' not found"
            );
        }

        $tool = $this->tools[$name];
        $output = $tool->execute($arguments);

        return JsonRpcResponse::success($request->id, $output);
    }

    private function handleResourcesList(JsonRpcRequest $request): JsonRpcResponse
    {
        $resources = [];
        foreach ($this->resources as $res) {
            $resources[] = $res->toMcpDefinition();
        }

        return JsonRpcResponse::success($request->id, [
            'resources' => $resources,
        ]);
    }

    private function handleResourcesRead(JsonRpcRequest $request): JsonRpcResponse
    {
        $uri = (string)($request->params['uri'] ?? '');
        if (!isset($this->resources[$uri])) {
            return JsonRpcResponse::error(
                $request->id,
                JsonRpcResponse::INVALID_PARAMS,
                "Resource '{$uri}' not found"
            );
        }

        $res = $this->resources[$uri];
        return JsonRpcResponse::success($request->id, $res->read());
    }

    private function handlePromptsList(JsonRpcRequest $request): JsonRpcResponse
    {
        $prompts = [];
        foreach ($this->prompts as $p) {
            $prompts[] = $p->toMcpDefinition();
        }

        return JsonRpcResponse::success($request->id, [
            'prompts' => $prompts,
        ]);
    }

    private function handlePromptsGet(JsonRpcRequest $request): JsonRpcResponse
    {
        $name = (string)($request->params['name'] ?? '');
        $args = isset($request->params['arguments']) && is_array($request->params['arguments'])
            ? $request->params['arguments']
            : [];

        if (!isset($this->prompts[$name])) {
            return JsonRpcResponse::error(
                $request->id,
                JsonRpcResponse::INVALID_PARAMS,
                "Prompt '{$name}' not found"
            );
        }

        $prompt = $this->prompts[$name];
        return JsonRpcResponse::success($request->id, $prompt->get($args));
    }
}
