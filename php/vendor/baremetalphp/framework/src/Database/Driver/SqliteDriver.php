<?php

declare(strict_types=1);

namespace BareMetalPHP\Database\Driver;

class SqliteDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'sqlite';
    }

    public function buildDsn(array $config): string
    {
        $database = $config['database'] ?? 'database.sqlite';
        
        // If it's an absolute path or contains :, use as-is
        if (strpos($database, ':') !== false || strpos($database, '/') === 0) {
            return 'sqlite:' . $database;
        }
        
        // Otherwise, treat as relative path
        return 'sqlite:' . $database;
    }

    public function quoteIdentifier(string $identifier): string
    {
        // SQLite uses square brackets for identifiers
        return '[' . str_replace(']', ']]', $identifier) . ']';
    }

    public function compileLimit(?int $limit, ?int $offset): string
    {
        $sql = '';
        
        if ($limit !== null) {
            $sql .= 'LIMIT ' . $limit;
        }
        
        if ($offset !== null) {
            $sql .= ' OFFSET ' . $offset;
        }
        
        return $sql;
    }

    public function getBooleanType(): string
    {
        return 'INTEGER';
    }

    public function getAutoIncrementType(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    public function getBigAutoIncrementType(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }
}

