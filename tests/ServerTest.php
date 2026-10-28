<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../src/autoload.php';

use PhpMcp\Server;
use PhpMcp\Tool;
use PhpMcp\Resource;
use PhpMcp\Prompt;
use PhpMcp\Protocol\JsonRpcRequest;
use PhpMcp\Protocol\JsonRpcResponse;
use PhpMcp\Attributes\McpTool;

$passed = 0;
$failed = 0;

function assertTrue($condition, $name) {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$name}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$name}\n";
        $failed++;
    }
}

echo "=== Running PHP-MCP Server Unit Tests ===\n\n";

$server = new Server('TestServer', '1.0.0');

// Test 1: Initialize
$initReq = new JsonRpcRequest('initialize', 'req_1', ['protocolVersion' => '2024-11-05']);
$initRes = $server->handle($initReq);
assertTrue($initRes instanceof JsonRpcResponse, 'handle(initialize) returns JsonRpcResponse');
assertTrue($initRes->result['protocolVersion'] === '2024-11-05', 'Negotiated protocol version is 2024-11-05');
assertTrue($initRes->result['serverInfo']['name'] === 'TestServer', 'Server name matches initialization');

// Test 2: Ping
$pingReq = new JsonRpcRequest('ping', 'req_2');
$pingRes = $server->handle($pingReq);
assertTrue($pingRes->id === 'req_2', 'Ping response matches request ID');

// Test 3: Tools Registration & List
$server->addTool(
    Tool::create('echo_tool')
        ->description('Echos the input message')
        ->param('msg', 'string', 'Message to echo', required: true)
        ->handler(fn(array $args) => ['echo' => $args['msg']])
);

$toolsReq = new JsonRpcRequest('tools/list', 'req_3');
$toolsRes = $server->handle($toolsReq);
assertTrue(count($toolsRes->result['tools']) === 1, 'tools/list returns 1 registered tool');
assertTrue($toolsRes->result['tools'][0]['name'] === 'echo_tool', 'Registered tool name is echo_tool');

// Test 4: Tools Execution (Call)
$callReq = new JsonRpcRequest('tools/call', 'req_4', [
    'name'      => 'echo_tool',
    'arguments' => ['msg' => 'Hello MCP'],
]);
$callRes = $server->handle($callReq);
assertTrue($callRes->result['isError'] === false, 'Tool execution succeeded without error');
assertTrue(strpos($callRes->result['content'][0]['text'], 'Hello MCP') !== false, 'Tool output contains echoed message');

// Test 5: Tool Not Found
$badCallReq = new JsonRpcRequest('tools/call', 'req_5', ['name' => 'non_existent']);
$badCallRes = $server->handle($badCallReq);
assertTrue($badCallRes->error !== null, 'Calling unknown tool returns JSON-RPC error');
assertTrue($badCallRes->error['code'] === JsonRpcResponse::INVALID_PARAMS, 'Error code is INVALID_PARAMS');

// Test 6: Resource Registration & Reading
$server->addResource(
    Resource::create('app://config', 'App Config')
        ->description('Application configuration')
        ->mimeType('application/json')
        ->reader(fn() => ['env' => 'testing', 'debug' => true])
);

$resListReq = new JsonRpcRequest('resources/list', 'req_6');
$resListRes = $server->handle($resListReq);
assertTrue(count($resListRes->result['resources']) === 1, 'resources/list returns 1 resource');

$resReadReq = new JsonRpcRequest('resources/read', 'req_7', ['uri' => 'app://config']);
$resReadRes = $server->handle($resReadReq);
assertTrue(strpos($resReadRes->result['contents'][0]['text'], 'testing') !== false, 'Resource read returns correct content');

// Test 7: Prompt Registration & Get
$server->addPrompt(
    Prompt::create('greet_prompt')
        ->description('Greeting prompt')
        ->arg('name', 'Name to greet', true)
        ->generator(fn($args) => "Hello, {$args['name']}!")
);

$promptListReq = new JsonRpcRequest('prompts/list', 'req_8');
$promptListRes = $server->handle($promptListReq);
assertTrue(count($promptListRes->result['prompts']) === 1, 'prompts/list returns 1 prompt');

$promptGetReq = new JsonRpcRequest('prompts/get', 'req_9', ['name' => 'greet_prompt', 'arguments' => ['name' => 'Alice']]);
$promptGetRes = $server->handle($promptGetReq);
assertTrue(strpos($promptGetRes->result['messages'][0]['content']['text'], 'Hello, Alice!') !== false, 'Prompt generation succeeded');

// Test 8: Attributes Class Discovery
class SampleController {
    /**
     * Multiply two numbers
     *
     * @param int $x The first factor
     * @param int $y The second factor
     */
    #[McpTool(name: 'multiply')]
    public function mul(int $x, int $y): int {
        return $x * $y;
    }

    /**
     * User profile query with optional title
     *
     * @param string $name User full name
     * @param string|null $title Optional title
     */
    #[McpTool]
    public function getProfile(string $name, ?string $title = 'Member'): array {
        return ['name' => $name, 'title' => $title];
    }
}

$server->registerClass(new SampleController());
$mulReq = new JsonRpcRequest('tools/call', 'req_10', [
    'name'      => 'multiply',
    'arguments' => ['x' => 6, 'y' => 7],
]);
$mulRes = $server->handle($mulReq);
assertTrue(strpos($mulRes->result['content'][0]['text'], '42') !== false, 'Attribute-based tool executed correctly (6*7=42)');

// Test 8b: Attributes DocBlock parameter description and type reflection
$toolsMap = $server->getTools();
$profTool = $toolsMap['getProfile']->toMcpDefinition();
$profProps = (array)$profTool['inputSchema']['properties'];
assertTrue($profProps['name']['type'] === 'string', 'getProfile name type is string');
assertTrue($profProps['title']['type'] === 'string', 'getProfile title type is string');
assertTrue(in_array('name', $profTool['inputSchema']['required'] ?? []), 'getProfile name is required');
assertTrue(!in_array('title', $profTool['inputSchema']['required'] ?? []), 'getProfile optional nullable title is not required');
assertTrue(strpos($profProps['name']['description'], 'User full name') !== false, 'Docblock @param description was parsed for name');

// Test 9: ResourceTemplate registration & resources/templates/list
$server->addResourceTemplate(\PhpMcp\ResourceTemplate::create('db://{table}/schema', 'Table Schema Template')->description('Schema template'));
$tmplRes = $server->handle(new JsonRpcRequest('resources/templates/list', 'req_11'));
assertTrue(count($tmplRes->result['resourceTemplates']) === 1, 'resources/templates/list returns 1 template');
assertTrue($tmplRes->result['resourceTemplates'][0]['uriTemplate'] === 'db://{table}/schema', 'Template URI template matches');

// Test 10: Resource subscription handling
$subRes = $server->handle(new JsonRpcRequest('resources/subscribe', 'req_12', ['uri' => 'db://schema']));
assertTrue($subRes !== null && $subRes->result !== null, 'resources/subscribe acknowledged successfully');
$unsubRes = $server->handle(new JsonRpcRequest('resources/unsubscribe', 'req_13', ['uri' => 'db://schema']));
assertTrue($unsubRes !== null && $unsubRes->result !== null, 'resources/unsubscribe acknowledged successfully');

// Test 11: Notification handling (returns null, no response sent)
$notifRes = $server->handle(new JsonRpcRequest('notifications/progress', null, ['progress' => 50]));
assertTrue($notifRes === null, 'JSON-RPC notifications return null (suppressed from wire)');

// Test 12: Tool required parameter validation
$missingReq = new JsonRpcRequest('tools/call', 'req_14', [
    'name' => 'echo_tool',
    'arguments' => [] // missing 'message'
]);
$missingRes = $server->handle($missingReq);
assertTrue($missingRes->result['isError'] === true, 'Missing required argument returns isError: true');
assertTrue(strpos($missingRes->result['content'][0]['text'], 'Validation Error') !== false, 'Validation error message returned');

// Test 13: Stress Benchmark (1,000 tool executions)
$benchStart = microtime(true);
$benchIterations = 1000;
for ($i = 0; $i < $benchIterations; $i++) {
    $server->handle(new JsonRpcRequest('tools/call', $i, ['name' => 'multiply', 'arguments' => ['x' => $i, 'y' => 2]]));
}
$benchElapsed = microtime(true) - $benchStart;
$avgLatencyMs = ($benchElapsed / $benchIterations) * 1000;
assertTrue($avgLatencyMs < 1.0, "1,000 tool calls average latency under 1.0ms (actual: " . round($avgLatencyMs, 4) . "ms)");

echo "\n---------------------------------------------------------\n";
echo "Results: {$passed} Passed, {$failed} Failed\n";
echo "---------------------------------------------------------\n";

if ($failed > 0) {
    exit(1);
}
echo "ALL SERVER UNIT TESTS PASSED SUCCESSFULLY!\n";
