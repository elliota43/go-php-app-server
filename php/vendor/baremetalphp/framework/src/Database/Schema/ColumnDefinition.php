<?php

declare(strict_types=1);

namespace BareMetalPHP\Database\Schema;

class ColumnDefinition
{
    public string $name;
    public string $type;
    public ?int $length = null;
    public ?int $precision = null;
    public ?int $scale = null;
    public bool $nullable = false;
    public mixed $default = null;
    public bool $primary = false;
    public bool $autoIncrement = false;
    public bool $unique = false;
    public bool $unsigned = false;
    public ?string $after = null;
    public ?string $comment = null;

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    public function nullable(bool $value = true): static
    {
        $this->nullable = $value;
        return $this;
    }

    public function default(mixed $value): static
    {
        $this->default = $value;
        return $this;
    }

    public function primary(bool $value = true): static
    {
        $this->primary = $value;
        return $this;
    }

    public function autoIncrement(bool $value = true): static
    {
        $this->autoIncrement = $value;
        return $this;
    }

    public function unique(bool $value = true): static
    {
        $this->unique = $value;
        return $this;
    }

    public function length(int $length): static
    {
        $this->length = $length;
        return $this;
    }

    public function unsigned(bool $value = true): static
    {
        $this->unsigned = $value;
        return $this;
    }

    public function precision(int $precision, int $scale = 0): static
    {
        $this->precision = $precision;
        $this->scale = $scale;
        return $this;
    }

    public function after(string $column): static
    {
        $this->after = $column;
        return $this;
    }

    public function comment(string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }
}