<?php

declare(strict_types=1);

namespace PhpMcp\Adapters;

use PDO;
use PhpMcp\Server;
use PhpMcp\Tool;
use PhpMcp\Resource;

class PdoAdapter
{
    private PDO $pdo;
    private bool $readOnly;
    private int $maxRows;

    public function __construct(PDO $pdo, bool $readOnly = true, int $maxRows = 100)
    {
        $this->pdo = $pdo;
        $this->readOnly = $readOnly;
        $this->maxRows = $maxRows;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Helper to instantiate and register in a single static call
     */
    public static function register(Server $server, PDO $pdo, bool $readOnly = true, int $maxRows = 100): self
    {
        $adapter = new self($pdo, $readOnly, $maxRows);
        $adapter->attach($server);
        return $adapter;
    }

    /**
     * Attaches database tools and schema resources to an MCP Server instance
     */
    public function attach(Server $server): self
    {
        // Tool 1: List Tables
        $server->addTool(
            Tool::create('db_list_tables')
                ->description('List all available tables in the database')
                ->handler(function () {
                    $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                    if ($driver === 'sqlite') {
                        $stmt = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                    } elseif ($driver === 'pgsql') {
                        $stmt = $this->pdo->query("SELECT table_name as name FROM information_schema.tables WHERE table_schema = 'public'");
                    } else {
                        // MySQL / MariaDB
                        $stmt = $this->pdo->query("SHOW TABLES");
                    }
                    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    return ['tables' => $tables, 'count' => count($tables)];
                })
        );

        // Tool 2: Describe Table Schema
        $server->addTool(
            Tool::create('db_describe_table')
                ->description('Retrieve schema details and column definitions for a specific table')
                ->param('table', 'string', 'Table name to inspect', required: true)
                ->handler(function (array $args) {
                    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$args['table']);
                    $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

                    if ($driver === 'sqlite') {
                        $stmt = $this->pdo->query("PRAGMA table_info({$table})");
                        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } elseif ($driver === 'pgsql') {
                        $stmt = $this->pdo->prepare("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = :t");
                        $stmt->execute(['t' => $table]);
                        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $stmt = $this->pdo->query("DESCRIBE `{$table}`");
                        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }

                    return [
                        'table'   => $table,
                        'columns' => $cols,
                        'count'   => count($cols),
                    ];
                })
        );

        // Tool 3: Execute SQL Query
        $server->addTool(
            Tool::create('db_query')
                ->description('Execute a SQL query against the database (SELECT only in read-only mode)')
                ->param('sql', 'string', 'SQL query to execute', required: true)
                ->param('params', 'object', 'Optional key-value parameters for prepared statement', required: false)
                ->handler(function (array $args) {
                    $sql = trim((string)$args['sql']);

                    if ($this->readOnly) {
                        $normalized = strtoupper(ltrim($sql));
                        if (!str_starts_with($normalized, 'SELECT') && !str_starts_with($normalized, 'PRAGMA') && !str_starts_with($normalized, 'EXPLAIN') && !str_starts_with($normalized, 'SHOW') && !str_starts_with($normalized, 'DESCRIBE')) {
                            throw new \RuntimeException('Permission denied: MCP database server is running in read-only mode (SELECT queries only).');
                        }
                    }

                    $params = isset($args['params']) && is_array($args['params']) ? $args['params'] : [];
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($params);

                    $rows = [];
                    $count = 0;
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $rows[] = $row;
                        $count++;
                        if ($count >= $this->maxRows) {
                            break;
                        }
                    }

                    return [
                        'rows'       => $rows,
                        'count'      => count($rows),
                        'rowCount'   => count($rows),
                        'truncated'  => $count >= $this->maxRows,
                    ];
                })
        );

        // Resource: db://schema
        $server->addResource(
            Resource::create('db://schema', 'Database Schema')
                ->description('Full schema summary of the database')
                ->mimeType('text/plain')
                ->reader(function () {
                    $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                    return "Database Driver: {$driver}\nDatabase connected successfully via PHP-MCP PdoAdapter.";
                })
        );

        return $this;
    }
}
