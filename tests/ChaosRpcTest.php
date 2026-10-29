<?php
/**
 * PHP-MCP Chaos & Adversarial Protocol Test Suite
 * Part of PHP-MCP by Anton Barinov
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use PhpMcp\Server;
use PhpMcp\Tool;
use PhpMcp\Transport\StdioTransport;
use PhpMcp\Adapters\PdoAdapter;

echo "=== Running PHP-MCP Chaos & Adversarial Protocol Tests ===\n\n";

$pass = 0;
$fail = 0;

function assertChaos(string $name, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        echo "  [PASS] {$name}" . ($detail ? " -> {$detail}" : "") . "\n";
        $pass++;
    } else {
        echo "  [FAIL] {$name}" . ($detail ? " -> {$detail}" : "") . "\n";
        $fail++;
    }
}

$server = new Server('ChaosServer', '1.0.0');
$server->tool('echo_test', fn(array $args) => ['msg' => $args['msg'] ?? ''], 'Echo tool', [
    'msg' => ['type' => 'string']
]);

// -------------------------------------------------------------
// 1. Malformed JSON & Protocol Violations
// -------------------------------------------------------------
echo "1. Testing Malformed Payloads & Protocol Edge Cases...\n";

// Truncated JSON
$r1 = $server->handleRaw('{"jsonrpc": "2.0", "method": "tools/list", "id":');
$d1 = json_decode((string)$r1, true);
assertChaos(
    "Truncated JSON returns Parse Error (-32700)",
    isset($d1['error']['code']) && $d1['error']['code'] === -32700,
    $d1['error']['message'] ?? ''
);

// Empty Batch array: []
$r2 = $server->handleRaw('[]');
$d2 = json_decode((string)$r2, true);
assertChaos(
    "Empty batch array [] returns Invalid Request (-32600)",
    isset($d2['error']['code']) && $d2['error']['code'] === -32600,
    $d2['error']['message'] ?? ''
);

// Invalid protocol version (e.g. jsonrpc: "1.0")
$r3 = $server->handleRaw('{"jsonrpc": "1.0", "method": "ping", "id": 1}');
$d3 = json_decode((string)$r3, true);
assertChaos(
    "Invalid jsonrpc version ('1.0') returns error code -32600",
    isset($d3['error']['code']) && $d3['error']['code'] === -32600,
    $d3['error']['message'] ?? ''
);

// Giant Payload: 1 MB string inside argument
$giantStr = str_repeat('A', 1048576);
$r4 = $server->handleRaw(json_encode([
    'jsonrpc' => '2.0',
    'id'      => 'giant_1',
    'method'  => 'tools/call',
    'params'  => ['name' => 'echo_test', 'arguments' => ['msg' => $giantStr]],
]));
$d4 = json_decode((string)$r4, true);
assertChaos(
    "1 MB payload processed without crash",
    isset($d4['result']['content'][0]['text']),
    "Handled 1MB payload cleanly"
);

// -------------------------------------------------------------
// 2. Heavy Batch Request (500 Items)
// -------------------------------------------------------------
echo "\n2. Testing Heavy Batch Request (500 mixed items)...\n";

$batch = [];
for ($i = 0; $i < 500; $i++) {
    if ($i % 3 === 0) {
        // Notification
        $batch[] = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];
    } elseif ($i % 3 === 1) {
        // Ping
        $batch[] = ['jsonrpc' => '2.0', 'id' => "ping_{$i}", 'method' => 'ping'];
    } else {
        // Malformed item
        $batch[] = "not_an_object";
    }
}

$t0 = microtime(true);
$rBatch = $server->handleRaw(json_encode($batch));
$t1 = microtime(true);
$dBatch = json_decode((string)$rBatch, true);

$elapsedMs = round(($t1 - $t0) * 1000, 2);
assertChaos(
    "500-item batch handled in < 100ms (actual: {$elapsedMs}ms)",
    $elapsedMs < 100 && is_array($dBatch) && count($dBatch) > 300,
    "Batch produced " . count($dBatch) . " responses (notifications suppressed)"
);

// -------------------------------------------------------------
// 3. PdoAdapter Adversarial Read-Only Bypass Attacks
// -------------------------------------------------------------
echo "\n3. Testing PdoAdapter Adversarial Read-Only Bypass Attacks...\n";

$pdo = new PDO('sqlite::memory:');
$pdo->exec("CREATE TABLE secrets (id INT, val TEXT)");
$pdo->exec("INSERT INTO secrets VALUES (1, 'classified')");

$dbServer = new Server('SecDb', '1.0.0');
$dbServer->addDatabase($pdo, readOnly: true);

$attackVectors = [
    'Comment disguised DROP'      => '/* comment */ DROP TABLE secrets;',
    'CTE Mutation Attack'         => 'WITH del AS (DELETE FROM secrets RETURNING *) SELECT * FROM del;',
    'Stacked Multi-Query'         => 'SELECT 1; DROP TABLE secrets;',
    'External DB Attach Attack'   => "ATTACH DATABASE '/tmp/evil.db' AS evil;",
    'PRAGMA Writable Schema Exploit' => 'PRAGMA writable_schema = ON;',
    'PRAGMA Auto Vacuum Exploit'  => 'PRAGMA auto_vacuum = 0;',
    'Stealth UPDATE Attack'       => 'UPDATE secrets SET val = "hacked";',
];

foreach ($attackVectors as $attackName => $sql) {
    $res = $dbServer->handleRaw(json_encode([
        'jsonrpc' => '2.0',
        'id'      => 'atk',
        'method'  => 'tools/call',
        'params'  => ['name' => 'db_query', 'arguments' => ['sql' => $sql]],
    ]));
    $resData = json_decode((string)$res, true);
    $blocked = isset($resData['result']['isError']) && $resData['result']['isError'] === true;
    assertChaos("Adversarial SQL blocked: {$attackName}", $blocked, "Protected by read-only guardrails");
}

// Ensure database table and contents are pristine
$stmt = $pdo->query("SELECT count(*) FROM secrets");
$count = (int)$stmt->fetchColumn();
assertChaos(
    "Database integrity preserved (secrets table intact with 1 row)",
    $count === 1,
    "Secrets table survived all attack vectors intact"
);

// -------------------------------------------------------------
// 4. Stdio Stream Disconnect & EOF Termination
// -------------------------------------------------------------
echo "\n4. Testing Stdio Stream Disconnect & EOF Termination...\n";

// Feed an empty stream that immediately closes
$inStream = fopen('php://memory', 'r+');
fwrite($inStream, '{"jsonrpc": "2.0", "id": 1, "method": "ping"}' . "\n");
rewind($inStream);

$outStream = fopen('php://memory', 'w+');
$transport = new StdioTransport($inStream, $outStream);

$t0 = microtime(true);
$transport->listen(fn($req) => $server->handle($req));
$t1 = microtime(true);

$eofElapsed = round(($t1 - $t0) * 1000, 2);
rewind($outStream);
$outContent = stream_get_contents($outStream);

assertChaos(
    "Stdio closes cleanly on EOF without infinite loop ({$eofElapsed}ms)",
    $eofElapsed < 50 && strpos($outContent, '"jsonrpc":"2.0"') !== false,
    "Responded to input and terminated gracefully on EOF"
);

echo "\n-------------------------------------------------------\n";
echo "Results: {$pass} Passed, {$fail} Failed\n";
echo "-------------------------------------------------------\n";

if ($fail > 0) {
    echo "CHAOS TESTS FAILED! ❌\n";
    exit(1);
}

echo "ALL CHAOS & ADVERSARIAL PROTOCOL TESTS PASSED! 🎉\n";
exit(0);
