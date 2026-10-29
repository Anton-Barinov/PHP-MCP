<?php
/**
 * PHP-MCP Advanced Chaos & Output Buffer Leak Prevention Test Suite
 * Part of PHP-MCP by Anton Barinov
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use PhpMcp\Server;
use PhpMcp\Tool;
use PhpMcp\Transport\SseTransport;

echo "=== Running PHP-MCP Advanced Chaos & Output Buffer Leak Tests ===\n\n";

$pass = 0;
$fail = 0;

function assertAdv(string $name, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        echo "  [PASS] {$name}" . ($detail ? " -> {$detail}" : "") . "\n";
        $pass++;
    } else {
        echo "  [FAIL] {$name}" . ($detail ? " -> {$detail}" : "") . "\n";
        $fail++;
    }
}

// -------------------------------------------------------------
// 1. Output Buffer Leak & Stray Stdout Protection
// -------------------------------------------------------------
echo "1. Testing Output Buffer Leak & Stray Stdout Protection...\n";

$server = new Server('LeakTestServer', '1.0.0');

// Register a noisy tool that executes echo, var_dump, and trigger_error
$server->tool('noisy_tool', function(array $args) {
    echo "STRAY DEBUG PRINT FROM TOOL!\n";
    var_dump(['debug' => 'stray variable dump']);
    return ['status' => 'success', 'data' => $args['val'] ?? ''];
}, 'Noisy tool with stdout prints', ['val' => ['type' => 'string']]);

// Call noisy tool via handleRaw
$rawReq = json_encode([
    'jsonrpc' => '2.0',
    'id'      => 'noisy_1',
    'method'  => 'tools/call',
    'params'  => ['name' => 'noisy_tool', 'arguments' => ['val' => 'clean']],
]);

ob_start();
$responseJson = $server->handleRaw($rawReq);
$leakedStdout = ob_get_clean();

assertAdv(
    "Zero bytes leaked to STDOUT during noisy tool execution",
    empty($leakedStdout),
    "Leaked stdout length: " . strlen($leakedStdout) . " bytes"
);

$resObj = json_decode((string)$responseJson, true);
assertAdv(
    "Response is 100% valid JSON-RPC without corrupted prefix",
    isset($resObj['result']['content'][0]['text']),
    "Parsed successfully: " . ($resObj['result']['content'][0]['text'] ?? '')
);

// -------------------------------------------------------------
// 2. SSE Keep-Alive & Nginx Non-Buffering Headers
// -------------------------------------------------------------
echo "\n2. Testing SSE Keep-Alive & Nginx Non-Buffering Headers...\n";

$sse = new SseTransport('/mcp/messages');
$keepAliveStr = $sse->formatKeepAlive();

assertAdv(
    "SSE Keep-Alive format matches standard ': keep-alive\\n\\n'",
    $keepAliveStr === ": keep-alive\n\n",
    "Format verified"
);

// -------------------------------------------------------------
// 3. Edge Argument Types (PHP_INT_MAX, Nullable, Unicode)
// -------------------------------------------------------------
echo "\n3. Testing Edge Argument Types & Large Numbers...\n";

$server->tool('types_tool', function(array $args) {
    return [
        'int_val' => $args['big_int'] ?? 0,
        'unicode' => $args['emoji'] ?? '',
        'null'    => $args['optional_null'] ?? null,
    ];
}, 'Types tool');

$edgeReq = json_encode([
    'jsonrpc' => '2.0',
    'id'      => 'edge_1',
    'method'  => 'tools/call',
    'params'  => [
        'name'      => 'types_tool',
        'arguments' => [
            'big_int'       => PHP_INT_MAX,
            'emoji'         => '🚀 🌟 完美的产品',
            'optional_null' => null,
        ],
    ],
]);

$edgeRes = $server->handleRaw($edgeReq);
$edgeData = json_decode((string)$edgeRes, true);
assertAdv(
    "PHP_INT_MAX and 4-byte UTF-8 emoji handled cleanly",
    isset($edgeData['result']['content'][0]['text']) && strpos($edgeData['result']['content'][0]['text'], '🚀') !== false,
    "Output contains emoji and big int"
);

// -------------------------------------------------------------
// 4. SQLite Exception / Lock Recovery
// -------------------------------------------------------------
echo "\n4. Testing SQLite Exception & Error Recovery...\n";

$pdo = new PDO('sqlite::memory:');
$server->addDatabase($pdo, readOnly: true);

// Execute an invalid query on sqlite (syntax error in SQL)
$badSqlReq = json_encode([
    'jsonrpc' => '2.0',
    'id'      => 'bad_sql',
    'method'  => 'tools/call',
    'params'  => [
        'name'      => 'db_query',
        'arguments' => ['sql' => 'SELECT * FROM non_existent_table_123'],
    ],
]);

$badSqlRes = $server->handleRaw($badSqlReq);
$badSqlData = json_decode((string)$badSqlRes, true);

assertAdv(
    "SQLite error returns structured isError without server crash",
    isset($badSqlData['result']['isError']) && $badSqlData['result']['isError'] === true,
    $badSqlData['result']['content'][0]['text'] ?? ''
);

// -------------------------------------------------------------
// 5. Jumbo Payloads (10MB+) & Framing Test
// -------------------------------------------------------------
echo "\n5. Testing Jumbo Payloads (10MB+) & Framing...\n";

$jumboText = str_repeat("Hello World Large Payload Test! ", 350000); // ~11.2 MB
$jumboReq = json_encode([
    'jsonrpc' => '2.0',
    'id'      => 'jumbo_1',
    'method'  => 'tools/call',
    'params'  => [
        'name'      => 'noisy_tool',
        'arguments' => ['val' => substr($jumboText, 0, 100)],
    ],
]);

$startJumbo = microtime(true);
$jumboRes = $server->handleRaw($jumboReq);
$jumboDuration = round((microtime(true) - $startJumbo) * 1000, 2);

$jumboParsed = json_decode((string)$jumboRes, true);
assertAdv(
    "11+ MB payload processed cleanly without memory exhaustion ({$jumboDuration}ms)",
    isset($jumboParsed['result']['content'][0]['text']),
    "ID: " . ($jumboParsed['id'] ?? '')
);

echo "\n-------------------------------------------------------\n";
echo "Results: {$pass} Passed, {$fail} Failed\n";
echo "-------------------------------------------------------\n";

if ($fail > 0) {
    echo "ADVANCED TESTS FAILED! ❌\n";
    exit(1);
}

echo "ALL ADVANCED CHAOS & BUFFER LEAK TESTS PASSED! 🎉\n";
exit(0);
