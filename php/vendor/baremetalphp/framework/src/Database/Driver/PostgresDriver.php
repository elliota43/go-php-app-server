<?php

declare(strict_types=1);

namespace BareMetalPHP\Database\Driver;

class PostgresDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'pgsql';
    }

    public function buildDsn(array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 5432;
        $database = $config['database'] ?? '';
        
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $port,
            $database
        );
        
        // Add optional parameters
        if (isset($config['charset'])) {
            $dsn .= ';options=\'--client_encoding=' . $config['charset'] . '\'';
        }
        
        return $dsn;
    }

    public function quoteIdentifier(string $identifier): string
    {
        // PostgreSQL uses double quotes for identifiers
        return '"' . str_replace('"', '""', $identifier) . '"';
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

    protected function prepareBoolean(bool $value): mixed
    {
        // PostgreSQL supports native boolean, return as-is
        return $value;
    }

    public function getBooleanType(): string
    {
        return 'BOOLEAN';
    }

    public function getAutoIncrementType(): string
    {
        return 'SERIAL PRIMARY KEY';
    }

    public function getBigAutoIncrementType(): string
    {
        return 'BIGSERIAL PRIMARY KEY';
    }
}

