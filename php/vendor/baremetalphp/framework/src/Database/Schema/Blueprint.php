<?php

declare(strict_types=1);

namespace BareMetalPHP\Database\Schema;

use BareMetalPHP\Database\Driver\DriverInterface;

class Blueprint
{
    protected DriverInterface $driver;
    public string $table;

    /** @var ColumnDefinition[] */
    public array $columns = [];

    /** @var array<string, array{type: string, columns: string[]}> */
    public array $indexes = [];

    /** @var array<string, ForeignKeyDefinition> */
    public array $foreignKeys = [];

    // For ALTER TABLE operations
    public bool $isAlter = false;
    public array $dropColumns = [];
    public array $renameColumns = [];
    public array $dropIndexes = [];
    public array $dropForeignKeys = [];
    public ?string $renameTable = null;

    public bool $timestamps = false;
    public bool $ifNotExists = false;
    public bool $temporary = false;

    public function __construct(DriverInterface $driver, string $table)
    {
        $this->driver = $driver;
        $this->table = $table;
    }

    // ----------------- column helpers -----------------

    public function id(string $name = 'id'): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'integer');
        $column->primary = true;
        $column->autoIncrement = true;
        $this->columns[] = $column;
        return $column;
    }

    public function bigId(string $name = 'id'): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'bigInteger');
        $column->primary = true;
        $column->autoIncrement = true;
        $this->columns[] = $column;
        return $column;
    }

    public function string(string $name, ?int $length = 255): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'string');
        if ($length !== null) {
            $column->length = $length;
        }
        $this->columns[] = $column;
        return $column;
    }

    public function text(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'text');
        $this->columns[] = $column;
        return $column;
    }

    public function mediumText(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'mediumText');
        $this->columns[] = $column;
        return $column;
    }

    public function longText(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'longText');
        $this->columns[] = $column;
        return $column;
    }

    public function integer(string $name, ?int $length = null): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'integer');
        if ($length !== null) {
            $column->length = $length;
        }
        $this->columns[] = $column;
        return $column;
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'bigInteger');
        $this->columns[] = $column;
        return $column;
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'smallInteger');
        $this->columns[] = $column;
        return $column;
    }

    public function tinyInteger(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'tinyInteger');
        $this->columns[] = $column;
        return $column;
    }

    public function boolean(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'boolean');
        $this->columns[] = $column;
        return $column;
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'decimal');
        $column->precision = $precision;
        $column->scale = $scale;
        $this->columns[] = $column;
        return $column;
    }

    public function float(string $name, ?int $precision = null, ?int $scale = null): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'float');
        if ($precision !== null) {
            $column->precision = $precision;
        }
        if ($scale !== null) {
            $column->scale = $scale;
        }
        $this->columns[] = $column;
        return $column;
    }

    public function double(string $name, ?int $precision = null, ?int $scale = null): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'double');
        if ($precision !== null) {
            $column->precision = $precision;
        }
        if ($scale !== null) {
            $column->scale = $scale;
        }
        $this->columns[] = $column;
        return $column;
    }

    public function date(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'date');
        $this->columns[] = $column;
        return $column;
    }

    public function dateTime(string $name, ?int $precision = null): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'dateTime');
        if ($precision !== null) {
            $column->precision = $precision;
        }
        $this->columns[] = $column;
        return $column;
    }

    public function time(string $name, ?int $precision = null): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'time');
        if ($precision !== null) {
            $column->precision = $precision;
        }
        $this->columns[] = $column;
        return $column;
    }

    public function timestamp(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'timestamp');
        $this->columns[] = $column;
        return $column;
    }

    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
        $this->timestamps = true;
    }

    public function nullableTimestamps(): void
    {
        $this->timestamps();
    }

    public function json(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'json');
        $this->columns[] = $column;
        return $column;
    }

    public function jsonb(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'jsonb');
        $this->columns[] = $column;
        return $column;
    }

    public function binary(string $name, ?int $length = null): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'binary');
        if ($length !== null) {
            $column->length = $length;
        }
        $this->columns[] = $column;
        return $column;
    }

    public function uuid(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'uuid');
        $this->columns[] = $column;
        return $column;
    }

    public function ipAddress(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'string');
        $column->length = 45; // IPv6 max length
        $this->columns[] = $column;
        return $column;
    }

    public function macAddress(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'string');
        $column->length = 17;
        $this->columns[] = $column;
        return $column;
    }

    public function year(string $name): ColumnDefinition
    {
        $column = new ColumnDefinition($name, 'year');
        $this->columns[] = $column;
        return $column;
    }

    // ----------------- indexes -----------------

    public function primary(array|string $columns, ?string $name = null): void
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $name = $name ?? 'primary';
        $this->indexes[$name] = [
            'type' => 'primary',
            'columns' => $columns,
        ];
    }

    public function unique(array|string $columns, ?string $name = null): void
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $name = $name ?? 'unique_' . implode('_', $columns);
        $this->indexes[$name] = [
            'type' => 'unique',
            'columns' => $columns,
        ];
    }

    public function index(array|string $columns, ?string $name = null): void
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $name = $name ?? 'index_' . implode('_', $columns);
        $this->indexes[$name] = [
            'type' => 'index',
            'columns' => $columns,
        ];
    }

    // ----------------- foreign keys -----------------

    public function foreign(array|string $columns, ?string $name = null): ForeignKeyDefinition
    {
        $columns = is_array($columns) ? $columns : [$columns];
        $name = $name ?? 'fk_' . $this->table . '_' . implode('_', $columns);
        
        $foreignKey = new ForeignKeyDefinition($name, $columns);
        $this->foreignKeys[$name] = $foreignKey;
        
        return $foreignKey;
    }

    // ----------------- table modifiers -----------------

    public function ifNotExists(): static
    {
        $this->ifNotExists = true;
        return $this;
    }

    public function temporary(): static
    {
        $this->temporary = true;
        return $this;
    }

    // ----------------- ALTER TABLE operations -----------------

    public function dropColumn(array|string $columns): static
    {
        $this->isAlter = true;
        $columns = is_array($columns) ? $columns : [$columns];
        $this->dropColumns = array_merge($this->dropColumns, $columns);
        return $this;
    }

    public function renameColumn(string $from, string $to): static
    {
        $this->isAlter = true;
        $this->renameColumns[$from] = $to;
        return $this;
    }

    public function dropIndex(array|string $indexes): static
    {
        $this->isAlter = true;
        $indexes = is_array($indexes) ? $indexes : [$indexes];
        $this->dropIndexes = array_merge($this->dropIndexes, $indexes);
        return $this;
    }

    public function dropForeignKey(string $name): static
    {
        $this->isAlter = true;
        $this->dropForeignKeys[] = $name;
        return $this;
    }

    public function rename(string $to): static
    {
        $this->isAlter = true;
        $this->renameTable = $to;
        return $this;
    }

    // ----------------- SQL compilation -----------------

    public function toSql(): string
    {
        $quotedTable = $this->driver->quoteIdentifier($this->table);
        $parts = [];
        
        if ($this->temporary) {
            $parts[] = 'CREATE TEMPORARY TABLE';
        } else {
            $parts[] = 'CREATE TABLE';
        }
        
        if ($this->ifNotExists) {
            $parts[] = 'IF NOT EXISTS';
        }
        
        $parts[] = $quotedTable;
        $parts[] = '(';
        
        // Build column definitions
        $columnDefinitions = [];
        
        foreach ($this->columns as $column) {
            $columnDefinitions[] = $this->compileColumn($column);
        }
        
        // Add primary key constraints if not already defined in column
        $hasPrimaryKey = false;
        foreach ($this->columns as $column) {
            if ($column->primary) {
                $hasPrimaryKey = true;
                break;
            }
        }
        
        // Add indexes
        foreach ($this->indexes as $name => $index) {
            if ($index['type'] === 'primary' && !$hasPrimaryKey) {
                $columns = array_map(fn($col) => $this->driver->quoteIdentifier($col), $index['columns']);
                $columnDefinitions[] = 'PRIMARY KEY (' . implode(', ', $columns) . ')';
                $hasPrimaryKey = true;
            }
        }
        
        // Add unique constraints
        foreach ($this->indexes as $name => $index) {
            if ($index['type'] === 'unique') {
                $columns = array_map(fn($col) => $this->driver->quoteIdentifier($col), $index['columns']);
                $columnDefinitions[] = 'UNIQUE (' . implode(', ', $columns) . ')';
            }
        }
        
        // Add regular indexes (these will be created separately for some drivers)
        foreach ($this->indexes as $name => $index) {
            if ($index['type'] === 'index') {
                $columns = array_map(fn($col) => $this->driver->quoteIdentifier($col), $index['columns']);
                $columnDefinitions[] = 'INDEX ' . $this->driver->quoteIdentifier($name) . ' (' . implode(', ', $columns) . ')';
            }
        }
        
        // Add foreign keys (only if fully defined)
        foreach ($this->foreignKeys as $name => $foreignKey) {
            // Skip incomplete foreign keys (missing references)
            if ($foreignKey->references === null || $foreignKey->on === null || empty($foreignKey->on)) {
                continue;
            }
            $columnDefinitions[] = $this->compileForeignKey($foreignKey);
        }
        
        $parts[] = implode(', ', $columnDefinitions);
        $parts[] = ')';
        
        $sql = implode(' ', $parts);
        
        // Add separate CREATE INDEX statements for databases that don't support inline indexes
        $extraIndexes = [];
        $driverName = $this->driver->getName();
        
        // PostgreSQL and MySQL support inline indexes, but SQLite doesn't for regular indexes
        if ($driverName === 'sqlite') {
            foreach ($this->indexes as $name => $index) {
                if ($index['type'] === 'index') {
                    $columns = array_map(fn($col) => $this->driver->quoteIdentifier($col), $index['columns']);
                    $extraIndexes[] = 'CREATE INDEX ' . $this->driver->quoteIdentifier($name) . ' ON ' . $quotedTable . ' (' . implode(', ', $columns) . ')';
                }
            }
        }
        
        if (!empty($extraIndexes)) {
            $sql = $sql . '; ' . implode('; ', $extraIndexes);
        }
        
        return $sql;
    }

    protected function compileColumn(ColumnDefinition $column): string
    {
        $quotedName = $this->driver->quoteIdentifier($column->name);
        $type = $this->compileColumnType($column);
        
        $definition = $quotedName . ' ' . $type;
        
        // Add nullable constraint
        if (!$column->nullable) {
            $definition .= ' NOT NULL';
        }
        
        // Add default value
        if ($column->default !== null) {
            $definition .= ' DEFAULT ' . $this->compileDefaultValue($column->default);
        }
        
        // Add primary key (if not already in type)
        if ($column->primary && !str_contains($type, 'PRIMARY KEY')) {
            $definition .= ' PRIMARY KEY';
        }
        
        // Add auto increment (if not already in type)
        if ($column->autoIncrement && !str_contains($type, 'AUTO_INCREMENT') && !str_contains($type, 'AUTOINCREMENT') && !str_contains($type, 'SERIAL')) {
            $definition .= ' ' . $this->getAutoIncrementKeyword();
        }
        
        // Add unique constraint
        if ($column->unique) {
            $definition .= ' UNIQUE';
        }
        
        // Add comment (MySQL/PostgreSQL)
        if ($column->comment !== null && $this->driver->getName() !== 'sqlite') {
            $comment = addslashes($column->comment);
            $definition .= ' COMMENT \'' . $comment . '\'';
        }
        
        // Add AFTER clause (MySQL only)
        if ($column->after !== null && $this->driver->getName() === 'mysql') {
            $definition .= ' AFTER ' . $this->driver->quoteIdentifier($column->after);
        }
        
        return $definition;
    }

    protected function compileColumnType(ColumnDefinition $column): string
    {
        $driverName = $this->driver->getName();
        
        return match ($column->type) {
            'string' => $this->compileStringType($column, $driverName),
            'text' => 'TEXT',
            'mediumText' => $driverName === 'mysql' ? 'MEDIUMTEXT' : 'TEXT',
            'longText' => $driverName === 'mysql' ? 'LONGTEXT' : 'TEXT',
            'integer' => $this->compileIntegerType($column, $driverName),
            'bigInteger' => $this->compileBigIntegerType($column, $driverName),
            'smallInteger' => $driverName === 'pgsql' ? 'SMALLINT' : 'SMALLINT',
            'tinyInteger' => $driverName === 'mysql' ? 'TINYINT' : 'INTEGER',
            'boolean' => $this->driver->getBooleanType(),
            'decimal' => $this->compileDecimalType($column),
            'float' => $this->compileFloatType($column, $driverName),
            'double' => $this->compileDoubleType($column, $driverName),
            'date' => 'DATE',
            'dateTime' => $this->compileDateTimeType($column, $driverName),
            'time' => $this->compileTimeType($column, $driverName),
            'timestamp' => $this->compileTimestampType($driverName),
            'json' => $this->compileJsonType($driverName),
            'jsonb' => $driverName === 'pgsql' ? 'JSONB' : $this->compileJsonType($driverName),
            'binary' => $this->compileBinaryType($column, $driverName),
            'uuid' => $this->compileUuidType($driverName),
            'year' => $driverName === 'mysql' ? 'YEAR' : 'INTEGER',
            default => 'TEXT',
        };
    }

    protected function compileStringType(ColumnDefinition $column, string $driverName): string
    {
        if ($column->length !== null) {
            if ($driverName === 'sqlite') {
                return 'VARCHAR(' . $column->length . ')';
            }
            return 'VARCHAR(' . $column->length . ')';
        }
        return 'TEXT';
    }

    protected function compileIntegerType(ColumnDefinition $column, string $driverName): string
    {
        $type = 'INTEGER';
        
        if ($column->unsigned && $driverName === 'mysql') {
            $type = 'INT UNSIGNED';
        }
        
        if ($column->primary && $column->autoIncrement) {
            return $this->driver->getAutoIncrementType();
        }
        
        return $type;
    }

    protected function compileBigIntegerType(ColumnDefinition $column, string $driverName): string
    {
        if ($column->primary && $column->autoIncrement) {
            return $this->driver->getBigAutoIncrementType();
        }
        
        $type = $driverName === 'pgsql' ? 'BIGINT' : 'BIGINT';
        
        if ($column->unsigned && $driverName === 'mysql') {
            $type = 'BIGINT UNSIGNED';
        }
        
        return $type;
    }

    protected function compileDecimalType(ColumnDefinition $column): string
    {
        $precision = $column->precision ?? 8;
        $scale = $column->scale ?? 2;
        return "DECIMAL({$precision}, {$scale})";
    }

    protected function compileFloatType(ColumnDefinition $column, string $driverName): string
    {
        if ($column->precision !== null && $column->scale !== null) {
            return "FLOAT({$column->precision}, {$column->scale})";
        }
        return 'FLOAT';
    }

    protected function compileDoubleType(ColumnDefinition $column, string $driverName): string
    {
        if ($column->precision !== null && $column->scale !== null) {
            return "DOUBLE({$column->precision}, {$column->scale})";
        }
        return 'DOUBLE';
    }

    protected function compileDateTimeType(ColumnDefinition $column, string $driverName): string
    {
        if ($driverName === 'pgsql') {
            return $column->precision !== null ? "TIMESTAMP({$column->precision})" : 'TIMESTAMP';
        }
        if ($driverName === 'mysql') {
            return $column->precision !== null ? "DATETIME({$column->precision})" : 'DATETIME';
        }
        return 'TEXT'; // SQLite
    }

    protected function compileTimeType(ColumnDefinition $column, string $driverName): string
    {
        if ($driverName === 'pgsql') {
            return $column->precision !== null ? "TIME({$column->precision})" : 'TIME';
        }
        if ($driverName === 'mysql') {
            return $column->precision !== null ? "TIME({$column->precision})" : 'TIME';
        }
        return 'TEXT'; // SQLite
    }

    protected function compileTimestampType(string $driverName): string
    {
        if ($driverName === 'pgsql') {
            return 'TIMESTAMP';
        }
        if ($driverName === 'mysql') {
            return 'TIMESTAMP';
        }
        return 'TEXT'; // SQLite
    }

    protected function compileJsonType(string $driverName): string
    {
        if ($driverName === 'pgsql') {
            return 'JSON';
        }
        if ($driverName === 'mysql') {
            return 'JSON';
        }
        return 'TEXT'; // SQLite
    }

    protected function compileBinaryType(ColumnDefinition $column, string $driverName): string
    {
        if ($column->length !== null) {
            if ($driverName === 'mysql') {
                return 'VARBINARY(' . $column->length . ')';
            }
            return 'BLOB';
        }
        return 'BLOB';
    }

    protected function compileUuidType(string $driverName): string
    {
        if ($driverName === 'pgsql') {
            return 'UUID';
        }
        return 'VARCHAR(36)';
    }

    protected function compileDefaultValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        
        if (is_bool($value)) {
            return $this->driver->prepareValue($value);
        }
        
        if (is_numeric($value)) {
            return (string) $value;
        }
        
        if (is_string($value)) {
            // Check if it's a special database function
            if (in_array(strtoupper($value), ['CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME'])) {
                return strtoupper($value);
            }
            return "'" . addslashes($value) . "'";
        }
        
        return "'" . addslashes((string) $value) . "'";
    }

    protected function compileForeignKey(ForeignKeyDefinition $foreignKey): string
    {
        // Validate that the foreign key is complete
        if ($foreignKey->references === null || $foreignKey->on === null || empty($foreignKey->on)) {
            throw new \RuntimeException(
                "Foreign key '{$foreignKey->name}' is incomplete. " .
                "You must call ->references('table', 'column') on the foreign key definition."
            );
        }
        
        $columns = array_map(fn($col) => $this->driver->quoteIdentifier($col), $foreignKey->columns);
        $quotedTable = $this->driver->quoteIdentifier($foreignKey->references);
        $references = array_map(fn($col) => $this->driver->quoteIdentifier($col), $foreignKey->on);
        
        $sql = 'CONSTRAINT ' . $this->driver->quoteIdentifier($foreignKey->name) . ' FOREIGN KEY (' . implode(', ', $columns) . ') REFERENCES ' . $quotedTable . ' (' . implode(', ', $references) . ')';
        
        if ($foreignKey->onDelete !== null) {
            $sql .= ' ON DELETE ' . $foreignKey->onDelete;
        }
        
        if ($foreignKey->onUpdate !== null) {
            $sql .= ' ON UPDATE ' . $foreignKey->onUpdate;
        }
        
        return $sql;
    }

    protected function getAutoIncrementKeyword(): string
    {
        $driverName = $this->driver->getName();
        
        return match ($driverName) {
            'mysql' => 'AUTO_INCREMENT',
            'sqlite' => 'AUTOINCREMENT',
            'pgsql' => '', // PostgreSQL uses SERIAL
            default => 'AUTO_INCREMENT',
        };
    }

    /**
     * Compile ALTER TABLE SQL statements
     */
    public function toAlterSql(): string
    {
        if (!$this->isAlter) {
            return '';
        }

        $quotedTable = $this->driver->quoteIdentifier($this->table);
        $driverName = $this->driver->getName();
        $statements = [];

        // Rename table
        if ($this->renameTable !== null) {
            $quotedNewTable = $this->driver->quoteIdentifier($this->renameTable);
            $statements[] = "ALTER TABLE {$quotedTable} RENAME TO {$quotedNewTable}";
            // After rename, subsequent operations should use the new table name
            $quotedTable = $quotedNewTable;
            $this->table = $this->renameTable;
        }

        // Drop columns
        foreach ($this->dropColumns as $column) {
            if ($driverName === 'sqlite') {
                // SQLite doesn't support DROP COLUMN easily - skip with warning
                // In production, you'd need to recreate the table
                continue;
            }
            $quotedColumn = $this->driver->quoteIdentifier($column);
            if ($driverName === 'mysql') {
                $statements[] = "ALTER TABLE {$quotedTable} DROP COLUMN {$quotedColumn}";
            } elseif ($driverName === 'pgsql') {
                $statements[] = "ALTER TABLE {$quotedTable} DROP COLUMN {$quotedColumn}";
            }
        }

        // Rename columns
        foreach ($this->renameColumns as $from => $to) {
            $quotedFrom = $this->driver->quoteIdentifier($from);
            $quotedTo = $this->driver->quoteIdentifier($to);
            
            if ($driverName === 'sqlite') {
                // SQLite requires a workaround - skip for now
                continue;
            } elseif ($driverName === 'mysql') {
                $statements[] = "ALTER TABLE {$quotedTable} RENAME COLUMN {$quotedFrom} TO {$quotedTo}";
            } elseif ($driverName === 'pgsql') {
                $statements[] = "ALTER TABLE {$quotedTable} RENAME COLUMN {$quotedFrom} TO {$quotedTo}";
            }
        }

        // Add new columns
        foreach ($this->columns as $column) {
            $quotedColumn = $this->driver->quoteIdentifier($column->name);
            $columnDef = $this->compileColumnType($column);
            
            $definition = $columnDef;
            
            // Add nullable constraint
            if (!$column->nullable) {
                $definition .= ' NOT NULL';
            }
            
            // Add default value
            if ($column->default !== null) {
                $definition .= ' DEFAULT ' . $this->compileDefaultValue($column->default);
            }
            
            // Add auto increment (if not already in type)
            if ($column->autoIncrement && !str_contains($definition, 'AUTO_INCREMENT') && !str_contains($definition, 'AUTOINCREMENT') && !str_contains($definition, 'SERIAL')) {
                $definition .= ' ' . $this->getAutoIncrementKeyword();
            }
            
            // Add unique constraint
            if ($column->unique) {
                $definition .= ' UNIQUE';
            }
            
            // Add AFTER clause (MySQL only)
            if ($driverName === 'mysql' && $column->after !== null) {
                $definition .= ' AFTER ' . $this->driver->quoteIdentifier($column->after);
            }
            
            $statements[] = "ALTER TABLE {$quotedTable} ADD COLUMN {$quotedColumn} {$definition}";
        }

        // Drop indexes
        foreach ($this->dropIndexes as $index) {
            $quotedIndex = $this->driver->quoteIdentifier($index);
            
            if ($driverName === 'sqlite') {
                $statements[] = "DROP INDEX IF EXISTS {$quotedIndex}";
            } elseif ($driverName === 'mysql') {
                $statements[] = "ALTER TABLE {$quotedTable} DROP INDEX {$quotedIndex}";
            } elseif ($driverName === 'pgsql') {
                $statements[] = "DROP INDEX IF EXISTS {$quotedIndex}";
            }
        }

        // Drop foreign keys
        foreach ($this->dropForeignKeys as $foreignKey) {
            $quotedFk = $this->driver->quoteIdentifier($foreignKey);
            
            if ($driverName === 'mysql') {
                $statements[] = "ALTER TABLE {$quotedTable} DROP FOREIGN KEY {$quotedFk}";
            } elseif ($driverName === 'pgsql') {
                $statements[] = "ALTER TABLE {$quotedTable} DROP CONSTRAINT {$quotedFk}";
            } elseif ($driverName === 'sqlite') {
                // SQLite doesn't support dropping foreign keys easily
                continue;
            }
        }

        // Add new indexes
        foreach ($this->indexes as $name => $index) {
            $columns = array_map(fn($col) => $this->driver->quoteIdentifier($col), $index['columns']);
            $quotedIndexName = $this->driver->quoteIdentifier($name);
            
            if ($index['type'] === 'primary') {
                // Primary keys are typically handled in CREATE TABLE
                continue;
            } elseif ($index['type'] === 'unique') {
                if ($driverName === 'mysql') {
                    $statements[] = "ALTER TABLE {$quotedTable} ADD UNIQUE {$quotedIndexName} (" . implode(', ', $columns) . ")";
                } elseif ($driverName === 'pgsql') {
                    $statements[] = "CREATE UNIQUE INDEX {$quotedIndexName} ON {$quotedTable} (" . implode(', ', $columns) . ")";
                } else {
                    $statements[] = "CREATE UNIQUE INDEX {$quotedIndexName} ON {$quotedTable} (" . implode(', ', $columns) . ")";
                }
            } elseif ($index['type'] === 'index') {
                $statements[] = "CREATE INDEX {$quotedIndexName} ON {$quotedTable} (" . implode(', ', $columns) . ")";
            }
        }

        // Add foreign keys (skip incomplete ones)
        foreach ($this->foreignKeys as $foreignKey) {
            // Skip incomplete foreign keys
            if ($foreignKey->references === null || $foreignKey->on === null || empty($foreignKey->on)) {
                continue;
            }
            
            $columns = array_map(fn($col) => $this->driver->quoteIdentifier($col), $foreignKey->columns);
            $quotedRefTable = $this->driver->quoteIdentifier($foreignKey->references);
            $references = array_map(fn($col) => $this->driver->quoteIdentifier($col), $foreignKey->on);
            $quotedFkName = $this->driver->quoteIdentifier($foreignKey->name);
            
            $sql = "ALTER TABLE {$quotedTable} ADD CONSTRAINT {$quotedFkName} FOREIGN KEY (" . implode(', ', $columns) . ") REFERENCES {$quotedRefTable} (" . implode(', ', $references) . ")";
            
            if ($foreignKey->onDelete !== null) {
                $sql .= ' ON DELETE ' . $foreignKey->onDelete;
            }
            
            if ($foreignKey->onUpdate !== null) {
                $sql .= ' ON UPDATE ' . $foreignKey->onUpdate;
            }
            
            $statements[] = $sql;
        }

        return implode('; ', $statements);
    }
}