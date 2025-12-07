<?php

declare(strict_types=1);

namespace BareMetalPHP\Database;

use PDO;
use BareMetalPHP\Support\Collection;
use BareMetalPHP\Database\SqlBuilder;

class Builder
{
    protected PDO $pdo;
    protected Connection $connection;
    protected string $table;
    protected ?string $modelClass;

    /**
     * Each where: ['AND'|'OR', column, operator, value]
     * @var array<int, array{0:string,1:string,2:string,3:mixed}>
     */
    protected array $wheres = [];

    /**
     * @var array<int, array{0:string,1:string}>
     */
    protected array $orders = [];

    protected ?int $limit = null;
    protected ?int $offset = null;

    public function __construct(PDO $pdo, string $table, ?string $modelClass = null, ?Connection $connection = null)
    {
        $this->pdo        = $pdo;
        $this->table      = $table;
        $this->modelClass = $modelClass;
        
        // Store connection for driver access
        // If not provided, try to get it from a static connection if available
        if ($connection === null) {
            // Try to create a connection from PDO (for backward compatibility)
            // This is a fallback - ideally connection should always be provided
            $this->connection = $this->createConnectionFromPdo($pdo);
        } else {
            $this->connection = $connection;
        }
    }

    /**
     * Create a connection wrapper from PDO (backward compatibility)
     */
    protected function createConnectionFromPdo(PDO $pdo): Connection
    {
        // This is a workaround for backward compatibility
        // In the future, Builder should always receive a Connection
        $connection = new \ReflectionClass(Connection::class);
        $instance = $connection->newInstanceWithoutConstructor();
        $pdoProperty = $connection->getProperty('pdo');
        $pdoProperty->setAccessible(true);
        $pdoProperty->setValue($instance, $pdo);
        
        return $instance;
    }

    /**
     * Apply a scope to the query
     */
    public function scope(string $name, ...$parameters): self
    {
        if ($this->modelClass && method_exists($this->modelClass, 'scope' . ucfirst($name))) {
            $method = 'scope' . ucfirst($name);
            $this->modelClass::$method($this, ...$parameters);
        }
        return $this;
    }

    /**
     * Magic method to call scopes dynamically
     */
    public function __call(string $method, array $parameters): self
    {
        // Check if it's a scope method
        if ($this->modelClass && method_exists($this->modelClass, 'scope' . ucfirst($method))) {
            return $this->scope($method, ...$parameters);
        }

        throw new \BadMethodCallException("Method {$method} does not exist on " . static::class);
    }

    /**
     * where('age', '>', 18)
     * where('status', 'active') // shorthand for '='
     */
    public function where(string $column, string $operator, mixed $value = null): self
    {
        // Allow shorthand: where('status', 'active')
        if ($value === null) {
            $value    = $operator;
            $operator = '=';
        }

        $this->wheres[] = ['AND', $column, $operator, $value];
        return $this;
    }

    public function orWhere(string $column, string $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value    = $operator;
            $operator = '=';
        }

        $this->wheres[] = ['OR', $column, $operator, $value];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }

        $this->orders[] = [$column, $direction];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    protected function compileSelect(): array
    {
        $driver = $this->connection->getDriver();
        $quotedTable = $driver->quoteIdentifier($this->table);
        
        $sql      = 'SELECT * FROM ' . $quotedTable;
        $bindings = [];

        // Build WHERE clause using SqlBuilder
        $whereClause = SqlBuilder::buildWhereClause($this->wheres, $driver, $bindings);
        $sql .= $whereClause;

        // Build ORDER BY clause using SqlBuilder
        $orderByClause = SqlBuilder::buildOrderByClause($this->orders, $driver);
        $sql .= $orderByClause;

        // Use driver's compileLimit method
        $limitClause = $driver->compileLimit($this->limit, $this->offset);
        if ($limitClause) {
            $sql .= ' ' . $limitClause;
        }

        return [$sql, $bindings];
    }

    public function get(): Collection
    {
        [$sql, $bindings] = $this->compileSelect();

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $rows = $stmt->fetchAll();

        if ($this->modelClass) {
            $class = $this->modelClass;
            $models = array_map(fn ($row) => new $class($row), $rows);
            return new Collection($models);
        }

        return new Collection($rows);
    }

    public function first()
    {
        $this->limit(1);
        $results = $this->get();
        return $results->first();
    }

    public function count(): int
    {
        // a very naive count implementation:
        [$sql, $bindings] = $this->compileSelect();
        $sql = preg_replace('/^SELECT \* FROM/i', 'SELECT COUNT(*) AS aggregate FROM', $sql);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        $row = $stmt->fetch();
        return (int) ($row['aggregate'] ?? 0);
    }

    public function toSql(): string
    {
        [$sql, ] = $this->compileSelect();
        return $sql;
    }
}
