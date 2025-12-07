<?php

declare(strict_types=1);

namespace BareMetalPHP\Database\Driver;

interface DriverInterface
{
    /**
     * Get the driver name (e.g., 'sqlite', 'mysql', 'pgsql')
     */
    public function getName(): string;

    /**
     * Build a DSN string from configuration array
     */
    public function buildDsn(array $config): string;

    /**
     * Quote an identifier (table name, column name, etc.)
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Compile LIMIT and OFFSET clause
     * 
     * @param int|null $limit
     * @param int|null $offset
     * @return string SQL fragment (e.g., "LIMIT 10 OFFSET 20" or "LIMIT 20, 10")
     */
    public function compileLimit(?int $limit, ?int $offset): string;

    /**
     * Convert a PHP value to a database-compatible value
     * Handles booleans, nulls, etc.
     */
    public function prepareValue(mixed $value): mixed;

    /**
     * Get the SQL type for a boolean column
     */
    public function getBooleanType(): string;

    /**
     * Get the SQL type for an auto-incrementing integer column
     */
    public function getAutoIncrementType(): string;

    /**
     * Get the SQL type for a big auto-incrementing integer column
     */
    public function getBigAutoIncrementType(): string;
}

