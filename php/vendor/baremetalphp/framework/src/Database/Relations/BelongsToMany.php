<?php

declare(strict_types=1);

namespace BareMetalPHP\Database\Relations;

use BareMetalPHP\Database\Model;
use BareMetalPHP\Support\Collection;
use BareMetalPHP\Database\Connection;

/**
 * Many-to-many relationship class
 */
class BelongsToMany
{
    protected Model $parent;
    protected string $related;
    protected string $pivotTable;
    protected string $foreignPivotKey;
    protected string $relatedPivotKey;
    protected string $parentKey;
    protected string $relatedKey;
    protected array $pivotColumns = [];

    public function __construct(
        Model $parent,
        string $related,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey = 'id',
        string $relatedKey = 'id'
    ) {
        $this->parent = $parent;
        $this->related = $related;
        $this->pivotTable = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;
    }

    /**
     * Get the related models
     */
    public function get(): Collection
    {
        $parentValue = $this->parent->getAttribute($this->parentKey) ?? null;
        if ($parentValue === null) {
            return new Collection();
        }

        // Get IDs from pivot table
        $connection = $this->parent::getConnection();
        $driver = $connection->getDriver();
        $pdo = $connection->pdo();
        
        $quotedPivotTable = $driver->quoteIdentifier($this->pivotTable);
        $quotedRelatedPivotKey = $driver->quoteIdentifier($this->relatedPivotKey);
        $quotedForeignPivotKey = $driver->quoteIdentifier($this->foreignPivotKey);
        
        $sql = "SELECT {$quotedRelatedPivotKey} FROM {$quotedPivotTable} WHERE {$quotedForeignPivotKey} = :parent_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['parent_id' => $parentValue]);
        $relatedIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($relatedIds)) {
            return new Collection();
        }

        // Fetch related models
        $relatedModels = [];
        foreach ($relatedIds as $relatedId) {
            $model = $this->related::find($relatedId);
            if ($model) {
                $relatedModels[] = $model;
            }
        }

        return new Collection($relatedModels);
    }

    /**
     * Attach a model (or models) to the relationship
     */
    public function attach(int|array $ids, array $pivotAttributes = []): void
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $parentValue = $this->parent->getAttribute($this->parentKey) ?? null;
        if ($parentValue === null) {
            return;
        }

        $connection = $this->parent::getConnection();
        $driver = $connection->getDriver();
        $pdo = $connection->pdo();

        $quotedPivotTable = $driver->quoteIdentifier($this->pivotTable);
        $quotedForeignPivotKey = $driver->quoteIdentifier($this->foreignPivotKey);
        $quotedRelatedPivotKey = $driver->quoteIdentifier($this->relatedPivotKey);

        foreach ($ids as $id) {
            // Check if already attached
            $checkSql = "SELECT COUNT(*) FROM {$quotedPivotTable} WHERE {$quotedForeignPivotKey} = :parent_id AND {$quotedRelatedPivotKey} = :related_id";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute(['parent_id' => $parentValue, 'related_id' => $id]);
            
            if ($checkStmt->fetchColumn() > 0) {
                // Update pivot attributes if already attached
                if (!empty($pivotAttributes)) {
                    $updateParts = [];
                    $updateBindings = [];
                    
                    foreach ($pivotAttributes as $key => $value) {
                        $quotedKey = $driver->quoteIdentifier($key);
                        $updateParts[] = "{$quotedKey} = :{$key}";
                        $updateBindings[$key] = $value;
                    }
                    
                    if (!empty($updateParts)) {
                        $updateSql = "UPDATE {$quotedPivotTable} SET " . implode(', ', $updateParts) 
                                   . " WHERE {$quotedForeignPivotKey} = :parent_id AND {$quotedRelatedPivotKey} = :related_id";
                        $updateBindings['parent_id'] = $parentValue;
                        $updateBindings['related_id'] = $id;
                        $updateStmt = $pdo->prepare($updateSql);
                        $updateStmt->execute($updateBindings);
                    }
                }
                continue;
            }

            // Insert new pivot record
            $insertAttributes = [
                $this->foreignPivotKey => $parentValue,
                $this->relatedPivotKey => $id,
            ];

            // Add pivot attributes
            foreach ($pivotAttributes as $key => $value) {
                $insertAttributes[$key] = $value;
            }

            // Add timestamps if they exist in the pivot table
            $now = date('Y-m-d H:i:s');
            if (!isset($insertAttributes['created_at'])) {
                $insertAttributes['created_at'] = $now;
            }
            if (!isset($insertAttributes['updated_at'])) {
                $insertAttributes['updated_at'] = $now;
            }

            $columns = array_keys($insertAttributes);
            $quotedColumns = array_map(fn($c) => $driver->quoteIdentifier($c), $columns);
            $placeholders = array_map(fn($c) => ":{$c}", $columns);

            $insertSql = "INSERT INTO {$quotedPivotTable} (" . implode(', ', $quotedColumns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $insertStmt = $pdo->prepare($insertSql);
            $insertStmt->execute($insertAttributes);
        }
    }

    /**
     * Detach a model (or models) from the relationship
     */
    public function detach(int|array|null $ids = null): int
    {
        $parentValue = $this->parent->getAttribute($this->parentKey) ?? null;
        if ($parentValue === null) {
            return 0;
        }

        $connection = $this->parent::getConnection();
        $driver = $connection->getDriver();
        $pdo = $connection->pdo();

        $quotedPivotTable = $driver->quoteIdentifier($this->pivotTable);
        $quotedForeignPivotKey = $driver->quoteIdentifier($this->foreignPivotKey);
        $quotedRelatedPivotKey = $driver->quoteIdentifier($this->relatedPivotKey);

        if ($ids === null) {
            // Detach all
            $sql = "DELETE FROM {$quotedPivotTable} WHERE {$quotedForeignPivotKey} = :parent_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['parent_id' => $parentValue]);
            return $stmt->rowCount();
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM {$quotedPivotTable} WHERE {$quotedForeignPivotKey} = :parent_id AND {$quotedRelatedPivotKey} IN ({$placeholders})";
        $bindings = array_merge(['parent_id' => $parentValue], $ids);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($bindings);

        return $stmt->rowCount();
    }

    /**
     * Sync the relationship - only the given IDs will be attached
     */
    public function sync(array $ids, bool $detaching = true): array
    {
        $current = $this->getPivotIds();
        
        $detach = [];
        $attach = [];

        if ($detaching) {
            $detach = array_diff($current, $ids);
        }

        $attach = array_diff($ids, $current);

        if (!empty($detach)) {
            $this->detach($detach);
        }

        if (!empty($attach)) {
            $this->attach($attach);
        }

        return [
            'attached' => array_values($attach),
            'detached' => array_values($detach),
        ];
    }

    /**
     * Get pivot IDs currently attached
     */
    protected function getPivotIds(): array
    {
        $parentValue = $this->parent->getAttribute($this->parentKey) ?? null;
        if ($parentValue === null) {
            return [];
        }

        $connection = $this->parent::getConnection();
        $driver = $connection->getDriver();
        $pdo = $connection->pdo();

        $quotedPivotTable = $driver->quoteIdentifier($this->pivotTable);
        $quotedRelatedPivotKey = $driver->quoteIdentifier($this->relatedPivotKey);
        $quotedForeignPivotKey = $driver->quoteIdentifier($this->foreignPivotKey);

        $sql = "SELECT {$quotedRelatedPivotKey} FROM {$quotedPivotTable} WHERE {$quotedForeignPivotKey} = :parent_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['parent_id' => $parentValue]);
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Get pivot attributes for a specific related model
     */
    public function getPivotAttributes(int $relatedId): array
    {
        $parentValue = $this->parent->getAttribute($this->parentKey) ?? null;
        if ($parentValue === null) {
            return [];
        }

        $connection = $this->parent::getConnection();
        $driver = $connection->getDriver();
        $pdo = $connection->pdo();

        $quotedPivotTable = $driver->quoteIdentifier($this->pivotTable);
        $quotedForeignPivotKey = $driver->quoteIdentifier($this->foreignPivotKey);
        $quotedRelatedPivotKey = $driver->quoteIdentifier($this->relatedPivotKey);

        $sql = "SELECT * FROM {$quotedPivotTable} WHERE {$quotedForeignPivotKey} = :parent_id AND {$quotedRelatedPivotKey} = :related_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['parent_id' => $parentValue, 'related_id' => $relatedId]);
        
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$row) {
            return [];
        }

        // Remove the foreign keys from pivot attributes
        unset($row[$this->foreignPivotKey], $row[$this->relatedPivotKey]);
        
        return $row;
    }
}

