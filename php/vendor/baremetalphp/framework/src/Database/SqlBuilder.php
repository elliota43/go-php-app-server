<?php

declare(strict_types=1);

namespace BareMetalPHP\Database;

use BareMetalPHP\Database\Driver\DriverInterface;

/**
 * Helper class for building common SQL patterns
 */
class SqlBuilder
{
    /**
     * Build a WHERE clause from where conditions
     */
    public static function buildWhereClause(
        array $wheres,
        DriverInterface $driver,
        array &$bindings
    ): string {
        if (empty($wheres)) {
            return '';
        }

        $parts = [];
        foreach ($wheres as $index => [$boolean, $column, $operator, $value]) {
            $quotedColumn = $driver->quoteIdentifier($column);

            // Handle NULL values
            if ($value === null) {
                if (strtoupper($operator) === '=' || strtoupper($operator) === 'IS') {
                    $clause = $quotedColumn . ' IS NULL';
                } elseif (strtoupper($operator) === '!=' || strtoupper($operator) === '<>' || strtoupper($operator) === 'IS NOT') {
                    $clause = $quotedColumn . ' IS NOT NULL';
                } else {
                    $clause = $quotedColumn . ' ' . $operator . ' NULL';
                }
                // No binding needed for NULL
            } else {
                $preparedValue = $driver->prepareValue($value);

                if (strtoupper($operator) === 'IN' && is_array($preparedValue)) {
                    $placeholders = implode(',', array_fill(0, count($preparedValue), '?'));
                    $clause = $quotedColumn . ' IN (' . $placeholders . ')';
                    $bindings = array_merge($bindings, $preparedValue);
                } elseif (strtoupper($operator) === 'NOT IN' && is_array($preparedValue)) {
                    $placeholders = implode(',', array_fill(0, count($preparedValue), '?'));
                    $clause = $quotedColumn . ' NOT IN (' . $placeholders . ')';
                    $bindings = array_merge($bindings, $preparedValue);
                } else {
                    $clause = $quotedColumn . ' ' . $operator . ' ?';
                    $bindings[] = $preparedValue;
                }
            }

            if ($index === 0) {
                $parts[] = $clause;
            } else {
                $parts[] = $boolean . ' ' . $clause;
            }
        }

        return ' WHERE ' . implode(' ', $parts);
    }

    /**
     * Build an ORDER BY clause from order conditions
     */
    public static function buildOrderByClause(array $orders, DriverInterface $driver): string
    {
        if (empty($orders)) {
            return '';
        }

        $orderParts = [];
        foreach ($orders as [$column, $dir]) {
            $quotedColumn = $driver->quoteIdentifier($column);
            $orderParts[] = $quotedColumn . ' ' . strtoupper($dir);
        }

        return ' ORDER BY ' . implode(', ', $orderParts);
    }
}

