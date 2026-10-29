<?php

declare(strict_types=1);

namespace PhpMcp;

use PDO;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionNamedType;
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
    /** @var array<string, ResourceTemplate> */
    private array $resourceTemplates = [];
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
     * @return array<string, ResourceTemplate>
     */
    public function getResourceTemplates(): array
    {
        return $this->resourceTemplates;
    }

    public function addResourceTemplate(ResourceTemplate $template): self
    {
        $this->resourceTemplates[$template->getUriTemplate()] = $template;
        return $this;
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

    /**
     * Process a raw JSON-RPC 2.0 payload in memory and return serialized JSON (or null for notifications)
     */
    public function handleRaw(string $rawJson): ?string
    {
        $decoded = json_decode($rawJson, true);
        if ($decoded === null && trim($rawJson) !== 'null') {
            return JsonRpcResponse::error(null, JsonRpcResponse::PARSE_ERROR, 'Parse error: Invalid JSON payload')->toJson();
        }

        // Support JSON-RPC 2.0 Batch request
        if (is_array($decoded) && array_is_list($decoded)) {
            $responses = [];
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    $responses[] = JsonRpcResponse::error(null, JsonRpcResponse::INVALID_REQUEST, 'Invalid Request: batch element must be an object')->toArray();
                    continue;
                }
                try {
                    $req = JsonRpcRequest::fromArray($item);
                    $res = $this->handle($req);
                    if ($res instanceof JsonRpcResponse && !$req->isNotification()) {
                        $responses[] = $res->toArray();
                    }
                } catch (Throwable $e) {
                    $responses[] = JsonRpcResponse::error($item['id'] ?? null, JsonRpcResponse::INVALID_REQUEST, $e->getMessage())->toArray();
                }
            }
            return empty($responses) ? null : json_encode($responses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (!is_array($decoded)) {
            return JsonRpcResponse::error(null, JsonRpcResponse::INVALID_REQUEST, 'Invalid Request: expected JSON object')->toJson();
        }

        try {
            $req = JsonRpcRequest::fromArray($decoded);
            $res = $this->handle($req);
            if ($res instanceof JsonRpcResponse && !$req->isNotification()) {
                return $res->toJson();
            }
            return null;
        } catch (Throwable $e) {
            return JsonRpcResponse::error($decoded['id'] ?? null, JsonRpcResponse::INVALID_REQUEST, $e->getMessage())->toJson();
        }
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
            $docData = $this->parseDocComment($method->getDocComment() ?: null);

            // Check #[McpTool]
            $toolAttrs = $method->getAttributes(McpTool::class);
            if (!empty($toolAttrs)) {
                /** @var McpTool $attr */
                $attr = $toolAttrs[0]->newInstance();
                $toolName = $attr->name ?: $method->getName();
                $toolDesc = $attr->description ?: ($docData['summary'] ?: "Executes {$method->getName()}");

                $tool = Tool::create($toolName)->description($toolDesc);

                foreach ($method->getParameters() as $param) {
                    $pName = $param->getName();
                    $pType = $this->resolveParamType($param);
                    $pDesc = $docData['params'][$pName] ?? "Parameter \${$pName}";
                    $isRequired = !$param->isOptional() && !$param->allowsNull() && !$param->isDefaultValueAvailable();

                    $tool->param($pName, $pType, $pDesc, $isRequired);
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
                $resDesc = $attr->description ?: ($docData['summary'] ?: "Resource {$method->getName()}");
                $res = Resource::create($attr->uri, $attr->name ?: $method->getName())
                    ->description($resDesc)
                    ->mimeType($attr->mimeType)
                    ->reader(fn($uri) => $method->invoke($obj, $uri));
                $this->addResource($res);
            }

            // Check #[McpPrompt]
            $promptAttrs = $method->getAttributes(McpPrompt::class);
            if (!empty($promptAttrs)) {
                /** @var McpPrompt $attr */
                $attr = $promptAttrs[0]->newInstance();
                $promptDesc = $attr->description ?: ($docData['summary'] ?: "Prompt {$method->getName()}");
                $prompt = Prompt::create($attr->name ?: $method->getName())
                    ->description($promptDesc)
                    ->generator(fn($args) => $method->invoke($obj, $args));
                $this->addPrompt($prompt);
            }
        }

        return $this;
    }

    private function resolveParamType(ReflectionParameter $param): string
    {
        $type = $param->getType();
        if ($type instanceof ReflectionNamedType) {
            $name = strtolower($type->getName());
            return match ($name) {
                'int', 'integer' => 'integer',
                'float', 'double' => 'number',
                'bool', 'boolean' => 'boolean',
                'array' => 'array',
                default => 'string',
            };
        }
        return 'string';
    }

    private function parseDocComment(?string $doc): array
    {
        $result = ['summary' => '', 'params' => []];
        if (!$doc) {
            return $result;
        }

        $lines = explode("\n", $doc);
        $summaryLines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line, "/* \t\r");
            if (str_starts_with($trimmed, '@param')) {
                if (preg_match('/@param\s+(?:[^\$]+)?\$([a-zA-Z0-9_]+)\s*(.*)/', $trimmed, $m)) {
                    $result['params'][$m[1]] = trim($m[2]);
                }
            } elseif (!str_starts_with($trimmed, '@') && $trimmed !== '') {
                $summaryLines[] = $trimmed;
            }
        }
        $result['summary'] = implode(' ', $summaryLines);
        return $result;
    }

    /**
     * Dispatch incoming JSON-RPC 2.0 requests
     */
    public function handle(JsonRpcRequest $request): ?JsonRpcResponse
    {
        // JSON-RPC 2.0: Notifications (no id) MUST NOT receive any response
        if ($request->isNotification() && !in_array($request->method, ['initialize', 'ping'], true)) {
            return null;
        }

        try {
            switch ($request->method) {
                case 'initialize':
                    return $this->handleInitialize($request);

                case 'notifications/initialized':
                case 'initialized':
                case 'notifications/cancelled':
                case 'notifications/progress':
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

                case 'resources/templates/list':
                    return $this->handleResourceTemplatesList($request);

                case 'resources/subscribe':
                case 'resources/unsubscribe':
                    return JsonRpcResponse::success($request->id, new \stdClass());

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

    private function handleResourceTemplatesList(JsonRpcRequest $request): JsonRpcResponse
    {
        $templates = [];
        foreach ($this->resourceTemplates as $t) {
            $templates[] = $t->toMcpDefinition();
        }

        return JsonRpcResponse::success($request->id, [
            'resourceTemplates' => $templates,
        ]);
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
