<?php

declare(strict_types=1);

namespace BareMetalPHP\Database\Driver;

class MysqlDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'mysql';
    }

    public function buildDsn(array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 3306;
        $database = $config['database'] ?? '';
        $charset = $config['charset'] ?? 'utf8mb4';
        
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );
    }

    public function quoteIdentifier(string $identifier): string
    {
        // MySQL uses backticks for identifiers
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function compileLimit(?int $limit, ?int $offset): string
    {
        $sql = '';
        
        // MySQL uses different syntax: LIMIT offset, count
        if ($limit !== null && $offset !== null) {
            $sql .= 'LIMIT ' . $offset . ', ' . $limit;
        } elseif ($limit !== null) {
            $sql .= 'LIMIT ' . $limit;
        } elseif ($offset !== null) {
            // MySQL doesn't support OFFSET without LIMIT, so use a large LIMIT
            $sql .= 'LIMIT 18446744073709551615 OFFSET ' . $offset;
        }
        
        return $sql;
    }

    public function getBooleanType(): string
    {
        return 'TINYINT(1)';
    }

    public function getAutoIncrementType(): string
    {
        return 'INT AUTO_INCREMENT PRIMARY KEY';
    }

    public function getBigAutoIncrementType(): string
    {
        return 'BIGINT AUTO_INCREMENT PRIMARY KEY';
    }
}

