<?php
/**
 * Instant Database MCP Server (SQLite / MySQL / Postgres)
 * Turns any database into an intelligent queryable agent tool in seconds.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use PhpMcp\Server;

// 1. Initialize sample database (or new PDO('mysql:host=localhost;dbname=shop', 'user', 'pass'))
$pdo = new PDO('sqlite::memory:');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, role TEXT)");
$pdo->exec("INSERT INTO users VALUES (1, 'Anton Barinov', 'anton@example.com', 'admin')");
$pdo->exec("INSERT INTO users VALUES (2, 'Jane Doe', 'jane@example.com', 'editor')");

// 2. Attach database to MCP server in read-only mode
$server = new Server('DatabaseMCP', '1.0.0');
$server->addDatabase($pdo, readOnly: true, maxRows: 50);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $server->listen();
}

return $server;
