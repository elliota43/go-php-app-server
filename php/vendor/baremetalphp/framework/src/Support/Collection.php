<?php

declare(strict_types=1);

namespace BareMetalPHP\Support;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use Traversable;

class Collection implements ArrayAccess, Countable, IteratorAggregate
{
    public function __construct(
        protected array $items = []
    ) {}

    public static function make(array $items = []): static
    {
        return new static($items);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [] || count($this->items) === 0;
    }

    public function count(): int 
    {
        return count($this->items);
    }

    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return $this->items[0] ?? $default;
        }

        foreach ($this->items as $item) {
            if ($callback($item)) {
                return $item;
            }
        }

        return $default;
    }

    public function map(callable $callback): static
    {
        $mapped = array_map($callback, $this->items);
        return new static($mapped);
    }

    public function filter(?callable $callback = null): static
    {
        if ($callback === null) {
            $filtered = array_filter($this->items);
        } else {
            $filtered = array_filter($this->items, $callback);
        }

        return new static($filtered);
    }

    public function pluck(string $key): static
    {
        $values = [];

        foreach ($this->items as $item) {
            if (is_array($item) && array_key_exists($key, $item)) {
                $values[] = $item[$key];
            } elseif (is_object($item)) {
                // Handle Model objects with getAttribute
                if (method_exists($item, 'getAttribute')) {
                    $values[] = $item->getAttribute($key);
                } elseif (isset($item->{$key})) {
                    $values[] = $item->{$key};
                }
            }
        }

        return new static($values);
    }

    /**
     * Get array of values from pluck
     */
    public function pluckToArray(string $key): array
    {
        return $this->pluck($key)->all();
    }

    public function push(mixed $item): static
    {
        $this->items[] = $item;
        return $this;
    }

    public function add(mixed $item): static
    {
        return $this->push($item);
    }

    public function toArray(): array
    {
        return array_map(function ($item) {
            if (is_object($item) && method_exists($item, 'toArray')) {
                return $item->toArray();
            }
            return $item;
        }, $this->items);
    }

    /* ====== Interfaces ====== */

    public function getIterator(): Traversable
    {
        yield from $this->items;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }
}