<?php

declare(strict_types=1);

namespace BareMetalPHP\Database;

use BareMetalPHP\Application;
use BareMetalPHP\Database\ConnectionManager;
use PDO;
use ArrayAccess;
use BareMetalPHP\Support\Collection;

abstract class Model implements ArrayAccess
{
    // Constants for common column names
    protected const PRIMARY_KEY = 'id';
    protected const CREATED_AT = 'created_at';
    protected const UPDATED_AT = 'updated_at';

    // Default connection name
    protected const DEFAULT_CONNECTION = 'default';

    protected static ?PDO $pdo = null;
    protected static ?Connection $connection = null;
    protected static string $connectionName = self::DEFAULT_CONNECTION;

    protected static string $table;

    protected bool $timestamps = true;

    protected array $attributes = [];

    protected array $relations = [];

    protected bool $exists = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
        if (isset($attributes['id'])) {
            $this->exists = true;
        }
    }

    // called once during bootstrap
    public static function setConnection(Connection $connection): void
    {
        static::$connection = $connection;
        static::$pdo = $connection->pdo();
    }

    /**
     * Set the connection name for this model
     */
    public static function setConnectionName(string $name): void
    {
        static::$connectionName = $name;
    }

    /**
     * Get the connection for this model
     */
    public static function getConnection(): Connection
    {
        if (static::$connection !== null) {
            return static::$connection;
        }

        // Try to get from ConnectionManager if available
        $app = Application::getInstance();
        if ($app) {
            try {
                $manager = $app->make(ConnectionManager::class);
                return $manager->connection(static::$connectionName);
            } catch (\Exception $e) {
                // Fallback to default connection
            }
        }

        // Final fallback: if we have a PDO, create a connection wrapper
        if (static::$pdo !== null) {
            // Use reflection to create connection without calling constructor
            $reflection = new \ReflectionClass(Connection::class);
            $connection = $reflection->newInstanceWithoutConstructor();
            $pdoProperty = $reflection->getProperty('pdo');
            $pdoProperty->setAccessible(true);
            $pdoProperty->setValue($connection, static::$pdo);
            
            // Set SQLite driver as default fallback
            $driver = new \BareMetalPHP\Database\Driver\SqliteDriver();
            $driverProperty = $reflection->getProperty('driver');
            $driverProperty->setAccessible(true);
            $driverProperty->setValue($connection, $driver);
            
            return $connection;
        }

        throw new \RuntimeException('No database connection available for ' . static::class);
    }
    

    // query builder entry
    public static function query(): Builder
    {
        $connection = static::getConnection();
        $pdo = $connection->pdo();
        
        $builder = new Builder($pdo, static::$table, static::class, $connection);
        
        // Apply global scopes if any
        static::applyGlobalScopes($builder);
        
        return $builder;
    }

    /**
     * Apply global scopes to the query builder
     */
    protected static function applyGlobalScopes(Builder $builder): void
    {
        // Override in child classes to add global scopes
        // Example:
        // $builder->where('active', '=', 1);
    }

    // basic query shortcuts

    public static function all(): Collection
    {
        return static::query()->get();
    }

    public static function find(int|string $id, string $column = 'id')
    {
        /** @var static|null $result */
        $result = static::query()
        ->where($column,'=', $id)
        ->first();

        return $result;
    }

    /**
     * Find a model by ID or throw an exception
     */
    public static function findOrFail(int|string $id, string $column = 'id'): static
    {
        $model = static::find($id, $column);
        
        if (!$model) {
            throw new \RuntimeException("Model not found: " . static::class . " with {$column} = {$id}");
        }
        
        return $model;
    }

    public static function where(string $column, string $operator,  mixed $value = null): Collection
    {
        return static::query()->where($column, $operator, $value)->get();
    }

    /**
     * Create a new model instance and save it
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /**
     * Update a model by ID (static helper)
     */
    public static function updateById(int|string $id, array $attributes): bool
    {
        $model = static::find($id);
        if (!$model) {
            return false;
        }
        return $model->update($attributes);
    }

    /**
     * Find or create a model
     */
    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        $model = static::query()
            ->where(key($attributes), '=', reset($attributes))
            ->first();

        if ($model) {
            return $model;
        }

        return static::create(array_merge($attributes, $values));
    }

    /**
     * Find or create a model, then update it
     */
    public static function updateOrCreate(array $attributes, array $values = []): static
    {
        $model = static::firstOrCreate($attributes, $values);
        
        if (!empty($values)) {
            $model->fill($values);
            $model->save();
        }

        return $model;
    }

    /**
     * Get the first model matching the attributes or create it
     */
    public static function firstOrNew(array $attributes, array $values = []): static
    {
        $model = static::query()
            ->where(key($attributes), '=', reset($attributes))
            ->first();

        if ($model) {
            return $model;
        }

        return new static(array_merge($attributes, $values));
    }



    public function save(): bool 
    {
        // naive: if "id" exists, update, else insert
        if ($this->exists || isset($this->attributes['id'])) {
            return $this->performUpdate();
        }

        return $this->performInsert();
    }

    /**
     * Fill the model with an array of attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
        return $this;
    }

    /**
     * Update the model with an array of attributes
     */
    public function update(array $attributes): bool
    {
        $this->fill($attributes);
        return $this->save();
    }

    /**
     * Refresh the model from the database
     */
    public function refresh(): static
    {
        if (!isset($this->attributes['id'])) {
            return $this;
        }

        $fresh = static::find($this->attributes['id']);
        if ($fresh) {
            $this->attributes = $fresh->attributes;
            $this->relations = [];
        }

        return $this;
    }

    /**
     * Get a fresh instance of the model from the database
     */
    public function fresh(): ?static
    {
        if (!isset($this->attributes['id'])) {
            return null;
        }

        return static::find($this->attributes['id']);
    }

    /**
     * Get an attribute value
     */
    public function getAttribute(string $key): mixed
    {
        // Accessor: getFullNameAttribute()
        if ($this->hasGetMutator($key)) {
            $method = $this->getGetMutatorName($key);
            return $this->{$method}();
        }
        return $this->attributes[$key] ?? null;
    }

    /**
     * Get all attributes
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Check if an attribute exists
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Check if the model exists in the database
     */
    public function exists(): bool
    {
        return $this->exists || isset($this->attributes['id']);
    }

    /**
     * Get connection components for database operations
     * Returns [Connection, Driver, PDO] tuple
     */
    protected function getConnectionComponents(): array
    {
        $connection = static::getConnection();
        return [$connection, $connection->getDriver(), $connection->pdo()];
    }

    /**
     * Prepare attributes using driver-specific value preparation
     */
    protected function prepareAttributes(array $attributes, \BareMetalPHP\Database\Driver\DriverInterface $driver): array
    {
        $prepared = [];
        foreach ($attributes as $key => $value) {
            $prepared[$key] = $driver->prepareValue($value);
        }
        return $prepared;
    }

    /**
     * Quote multiple identifiers at once
     */
    protected function quoteIdentifiers(array $identifiers, \BareMetalPHP\Database\Driver\DriverInterface $driver): array
    {
        return array_map(fn ($id) => $driver->quoteIdentifier($id), $identifiers);
    }

    /**
     * Update timestamps if enabled
     */
    protected function updateTimestamps(bool $create = false): void
    {
        if (!$this->timestamps) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        
        if ($create) {
            $this->attributes[self::CREATED_AT] = $this->attributes[self::CREATED_AT] ?? $now;
        }
        
        $this->attributes[self::UPDATED_AT] = $now;
    }

    protected function performInsert(): bool
    {
        $this->updateTimestamps(true);

        [$connection, $driver, $pdo] = $this->getConnectionComponents();
        $preparedAttributes = $this->prepareAttributes($this->attributes, $driver);

        $columns = array_keys($preparedAttributes);
        $quotedColumns = $this->quoteIdentifiers($columns, $driver);
        $placeholders = array_map(fn ($c) => ':' . $c, $columns);
        $quotedTable = $driver->quoteIdentifier(static::$table);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $quotedTable,
            implode(', ', $quotedColumns),
            implode(', ', $placeholders)
        );

        $stmt = $pdo->prepare($sql);
        $ok = $stmt->execute($preparedAttributes);

        if ($ok) {
            $this->attributes['id'] = (int) $pdo->lastInsertId();
            $this->exists = true;
        }

        return $ok;
    }

    protected function performUpdate(): bool
    {
        $this->updateTimestamps();
        
        [$connection, $driver, $pdo] = $this->getConnectionComponents();
        $preparedAttributes = $this->prepareAttributes($this->attributes, $driver);

        $assignments = [];
        foreach (array_keys($preparedAttributes) as $column) {
            if ($column === self::PRIMARY_KEY) {
                continue;
            }
            $quotedColumn = $driver->quoteIdentifier($column);
            $assignments[] = $quotedColumn . ' = :' . $column;
        }

        $quotedTable = $driver->quoteIdentifier(static::$table);
        $quotedIdColumn = $driver->quoteIdentifier('id');

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :id',
            $quotedTable,
            implode(', ', $assignments),
            $quotedIdColumn
        );

        $stmt = $pdo->prepare($sql);
        return $stmt->execute($preparedAttributes);
    }

    public function delete(): bool
    {
        if (!isset($this->attributes[self::PRIMARY_KEY])) {
            return false;
        }

        [$connection, $driver, $pdo] = $this->getConnectionComponents();

        $quotedTable = $driver->quoteIdentifier(static::$table);
        $quotedIdColumn = $driver->quoteIdentifier(self::PRIMARY_KEY);
        $sql = 'DELETE FROM ' . $quotedTable . ' WHERE ' . $quotedIdColumn . ' = :id';
        
        $stmt = $pdo->prepare($sql);
        return $stmt->execute(['id' => $this->attributes[self::PRIMARY_KEY]]);
    }
    
    // relationships

    /**
     * Define a one-to-many relationship
     */
    protected function hasMany(string $related, string $foreignKey, string $localKey = 'id'): Collection
    {
        $localValue = $this->attributes[$localKey] ?? null;
        if ($localValue === null) {
            return new Collection();
        }

        /** @var Model $related */
        $result = $related::query()
            ->where($foreignKey, '=', $localValue)
            ->get();
        
        // Ensure we always return a Collection, never null
        return $result instanceof Collection ? $result : new Collection();
    }

    /**
     * Define a one-to-one relationship
     */
    protected function hasOne(string $related, string $foreignKey, string $localKey = 'id'): ?Model
    {
        $localValue = $this->attributes[$localKey] ?? null;
        if ($localValue === null) {
            return null;
        }

        /** @var Model $related */
        return $related::query()
            ->where($foreignKey, '=', $localValue)
            ->first();
    }

    /**
     * Define an inverse one-to-one or many-to-one relationship
     */
    protected function belongsTo(string $related, string $foreignKey, string $ownerKey = 'id'): ?Model
    {
        $foreignValue = $this->attributes[$foreignKey] ?? null;
        if ($foreignValue === null) {
            return null;
        }

        /** @var Model $related */
        return $related::query()
            ->where($ownerKey, '=', $foreignValue)->first();
    }

    /**
     * Define a many-to-many relationship
     * 
     * @param string $related The related model class
     * @param string|null $pivotTable The pivot table name (auto-generated if null)
     * @param string|null $foreignPivotKey Foreign key for this model in pivot table (auto-generated if null)
     * @param string|null $relatedPivotKey Foreign key for related model in pivot table (auto-generated if null)
     * @param string $parentKey Local key on this model
     * @param string $relatedKey Local key on related model
     * 
     * @return \BareMetalPHP\Database\Relations\BelongsToMany
     */
    protected function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        string $parentKey = 'id',
        string $relatedKey = 'id'
    ): \BareMetalPHP\Database\Relations\BelongsToMany {
        // Auto-generate pivot table name if not provided (Laravel convention: alphabetically sorted model names)
        if ($pivotTable === null) {
            $tables = [static::$table, (new $related)::$table];
            sort($tables);
            $pivotTable = implode('_', $tables);
        }

        // Auto-generate foreign key names if not provided (Laravel convention: snake_case model name + _id)
        if ($foreignPivotKey === null) {
            $foreignPivotKey = $this->getModelForeignKeyName();
        }

        if ($relatedPivotKey === null) {
            $relatedModel = new $related();
            $relatedPivotKey = $relatedModel->getModelForeignKeyName();
        }

        return new \BareMetalPHP\Database\Relations\BelongsToMany(
            $this,
            $related,
            $pivotTable,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey
        );
    }

    /**
     * Get the foreign key name for this model (for pivot tables)
     */
    protected function getModelForeignKeyName(): string
    {
        // Convert "App\Models\Project" -> "project" -> "project_id"
        $className = (new \ReflectionClass($this))->getShortName();
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $className)) . '_id';
    }

    /**
     * Define a has-many-through relationship
     */
    protected function hasManyThrough(
        string $related,
        string $through,
        string $firstKey,
        string $secondKey,
        string $localKey = 'id',
        string $secondLocalKey = 'id'
    ): Collection {
        $localValue = $this->attributes[$localKey] ?? null;
        if ($localValue === null) {
            return new Collection();
        }

        // Get intermediate IDs
        /** @var Model $through */
        $throughModels = $through::query()
            ->where($firstKey, '=', $localValue)
            ->get();

        if ($throughModels->isEmpty()) {
            return new Collection();
        }

        $throughIds = $throughModels->pluck($secondLocalKey)->all();

        /** @var Model $related */
        return $related::query()
            ->where($secondKey, 'IN', $throughIds)
            ->get();
    }


    // Accessors

    public function setAttribute(string $key, mixed $value): void
    {
        // Mutator: setEmailAttribute($value)
        if ($this->hasSetMutator($key)) {
            $method = $this->getSetMutatorName($key);
            $this->{$method}($value);
            return;
        }
        $this->attributes[$key] = $value;
    }

    public function toArray(): array
    {
        $array = $this->attributes;
        
        // Include loaded relationships
        foreach ($this->relations as $key => $relation) {
            if ($relation instanceof Collection) {
                $array[$key] = $relation->toArray();
            } elseif ($relation instanceof Model) {
                $array[$key] = $relation->toArray();
            } else {
                $array[$key] = $relation;
            }
        }
        
        return $array;
    }

    protected function keyToStudly(string $key): string
    {
        // "full_name" -> "FullName"
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
    }

    protected function getGetMutatorName(string $key): string
    {
        return 'get' . $this->keyToStudly($key) . 'Attribute';
    }

    protected function getSetMutatorName(string $key): string
    {
        return 'set' . $this->keyToStudly($key) . 'Attribute';
    }

    protected function hasGetMutator(string $key): bool
    {
        return method_exists($this, $this->getGetMutatorName($key));
    }

    protected function hasSetMutator(string $key): bool
    {
        return method_exists($this, $this->getSetMutatorName($key));
    }

    public function __get(string $key): mixed
    {
        // 1) normal attribute or accessor
        if (array_key_exists($key, $this->attributes) || $this->hasGetMutator($key)) {
            return $this->getAttribute($key);
        }

        // 2) Check if relationship is already loaded
        if (isset($this->relations[$key])) {
            return $this->relations[$key];
        }

        // 3) Relationship-style method:
        //      If there's a method with this name, call it and cache result.
        if (method_exists($this, $key)) {
            try {
                $value = $this->{$key}(); // e.g. $this->posts()
                
                // Handle BelongsToMany relationship objects - call get() to get Collection
                if ($value instanceof \BareMetalPHP\Database\Relations\BelongsToMany) {
                    $value = $value->get();
                }
                
                // Ensure relationships always return a Collection if they're supposed to return collections
                // If null is returned, return empty collection for safety
                if ($value === null) {
                    $value = new Collection();
                }
                $this->relations[$key] = $value; // cache relationship separately from attributes
                return $value;
            } catch (\Exception $e) {
                // If relationship fails, return empty collection instead of null
                $value = new Collection();
                $this->relations[$key] = $value;
                return $value;
            }
        }

        // 4) fallback
        return null;
    }

    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    public function offsetGet(mixed $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function offsetExists(mixed $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function offsetUnset(mixed $key): void
    {
        unset($this->attributes[$key]);
    }
}