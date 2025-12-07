<?php

declare(strict_types=1);

namespace BareMetalPHP\Database\Driver;

abstract class AbstractDriver implements DriverInterface
{
    /**
     * Get the driver name
     */
    abstract public function getName(): string;

    /**
     * Build a DSN string from configuration array
     */
    abstract public function buildDsn(array $config): string;

    /**
     * Quote an identifier (table name, column name, etc.)
     */
    abstract public function quoteIdentifier(string $identifier): string;

    /**
     * Compile LIMIT and OFFSET clause
     */
    abstract public function compileLimit(?int $limit, ?int $offset): string;

    /**
     * Convert a PHP value to a database-compatible value
     */
    public function prepareValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $this->prepareBoolean($value);
        }

        return $value;
    }

    /**
     * Convert boolean to database representation
     */
    protected function prepareBoolean(bool $value): mixed
    {
        return $value ? 1 : 0;
    }

    /**
     * Get the SQL type for a boolean column
     */
    public function getBooleanType(): string
    {
        return 'INTEGER';
    }

    /**
     * Get the SQL type for an auto-incrementing integer column
     */
    public function getAutoIncrementType(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    /**
     * Get the SQL type for a big auto-incrementing integer column
     */
    public function getBigAutoIncrementType(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }
}

