<?php

declare(strict_types=1);

namespace PhpMcp\Tests;

use PDO;
use PhpMcp\Adapters\PdoAdapter;
use PhpMcp\Server;
use PhpMcp\Protocol\JsonRpcRequest;

require_once __DIR__ . '/../src/autoload.php';

echo "Running PdoAdapterTest...\n";

// 1. Create in-memory SQLite database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, created_at TEXT)');
$pdo->exec("INSERT INTO users (name, email, created_at) VALUES ('Alice', 'alice@example.com', '2026-01-01')");
$pdo->exec("INSERT INTO users (name, email, created_at) VALUES ('Bob', 'bob@example.com', '2026-01-02')");

$server = new Server('test-db', '1.0.0');
PdoAdapter::register($server, $pdo, readOnly: true);

// 2. Test tool registration
$tools = $server->getTools();
assert(isset($tools['db_list_tables']), 'db_list_tables should be registered');
assert(isset($tools['db_describe_table']), 'db_describe_table should be registered');
assert(isset($tools['db_query']), 'db_query should be registered');
echo "  ✓ Tools registered correctly\n";

// 3. Test db_list_tables
$res = $server->handleRequest(new JsonRpcRequest(
    method: 'tools/call',
    params: ['name' => 'db_list_tables', 'arguments' => []],
    id: 1
));
$body = $res->result['content'][0]['text'];
$data = json_decode($body, true);
assert(in_array('users', $data['tables']), 'users table should be listed');
echo "  ✓ db_list_tables returned users table\n";

// 4. Test db_describe_table
$res = $server->handleRequest(new JsonRpcRequest(
    method: 'tools/call',
    params: ['name' => 'db_describe_table', 'arguments' => ['table' => 'users']],
    id: 2
));
$body = $res->result['content'][0]['text'];
$data = json_decode($body, true);
assert(count($data['columns']) === 4, 'users table should have 4 columns');
echo "  ✓ db_describe_table returned table schema\n";

// 5. Test db_query SELECT
$res = $server->handleRequest(new JsonRpcRequest(
    method: 'tools/call',
    params: ['name' => 'db_query', 'arguments' => ['sql' => 'SELECT * FROM users WHERE name = :name', 'params' => ['name' => 'Alice']]],
    id: 3
));
$body = $res->result['content'][0]['text'];
$data = json_decode($body, true);
assert($data['rowCount'] === 1, 'Should find 1 user');
assert($data['rows'][0]['email'] === 'alice@example.com', 'Alice email should match');
echo "  ✓ db_query SELECT worked\n";

// 6. Test db_query ReadOnly protection
$res = $server->handleRequest(new JsonRpcRequest(
    method: 'tools/call',
    params: ['name' => 'db_query', 'arguments' => ['sql' => "DROP TABLE users"]],
    id: 4
));
assert($res->result['isError'] === true, 'DROP TABLE should be rejected in read-only mode');
echo "  ✓ Read-only mode blocked DROP TABLE\n";

echo "PdoAdapterTest passed successfully!\n";
