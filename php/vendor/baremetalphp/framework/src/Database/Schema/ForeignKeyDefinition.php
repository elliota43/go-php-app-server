<?php

declare(strict_types=1);

namespace BareMetalPHP\Database\Schema;

class ForeignKeyDefinition
{
    public string $name;
    public array $columns;
    public ?string $references = null;
    public ?array $on = null;
    public ?string $onDelete = null;
    public ?string $onUpdate = null;

    public function __construct(string $name, array $columns)
    {
        $this->name = $name;
        $this->columns = $columns;
    }

    public function references(string $table, array|string $columns): static
    {
        $this->references = $table;
        $this->on = is_array($columns) ? $columns : [$columns];
        return $this;
    }

    public function onDelete(string $action): static
    {
        $this->onDelete = $action;
        return $this;
    }

    public function onUpdate(string $action): static
    {
        $this->onUpdate = $action;
        return $this;
    }

    public function cascade(): static
    {
        $this->onDelete('CASCADE');
        $this->onUpdate('CASCADE');
        return $this;
    }

    public function restrict(): static
    {
        $this->onDelete('RESTRICT');
        $this->onUpdate('RESTRICT');
        return $this;
    }

    public function setNull(): static
    {
        $this->onDelete('SET NULL');
        return $this;
    }
}

