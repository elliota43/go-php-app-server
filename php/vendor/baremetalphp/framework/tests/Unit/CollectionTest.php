<?php

declare(strict_types=1);

namespace Tests\Unit;

use BareMetalPHP\Support\Collection;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    public function testCanCreateCollection(): void
    {
        $collection = new Collection([1, 2, 3]);
        
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertEquals(3, $collection->count());
    }

    public function testCanGetAllItems(): void
    {
        $items = [1, 2, 3];
        $collection = new Collection($items);
        
        $this->assertEquals($items, $collection->all());
    }

    public function testCanCheckIfEmpty(): void
    {
        $empty = new Collection([]);
        $notEmpty = new Collection([1]);
        
        $this->assertTrue($empty->isEmpty());
        $this->assertFalse($notEmpty->isEmpty());
    }

    public function testCanGetFirstItem(): void
    {
        $collection = new Collection([1, 2, 3]);
        
        $this->assertEquals(1, $collection->first());
    }

    public function testCanGetFirstWithCallback(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        
        $result = $collection->first(fn($item) => $item > 3);
        
        $this->assertEquals(4, $result);
    }

    public function testCanMapItems(): void
    {
        $collection = new Collection([1, 2, 3]);
        $mapped = $collection->map(fn($item) => $item * 2);
        
        $this->assertEquals([2, 4, 6], $mapped->all());
    }

    public function testCanFilterItems(): void
    {
        $collection = new Collection([1, 2, 3, 4, 5]);
        $filtered = $collection->filter(fn($item) => $item > 3);
        
        // Filter preserves keys, so we check values match
        $this->assertEquals([4, 5], array_values($filtered->all()));
    }

    public function testCanPluckValues(): void
    {
        $items = [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ];
        $collection = new Collection($items);
        $names = $collection->pluck('name');
        
        $this->assertEquals(['John', 'Jane'], $names->all());
    }

    public function testCanPushItem(): void
    {
        $collection = new Collection([1, 2]);
        $collection->push(3);
        
        $this->assertEquals(3, $collection->count());
        $this->assertEquals(3, $collection->all()[2]);
    }

    public function testCanConvertToArray(): void
    {
        $collection = new Collection([1, 2, 3]);
        
        $this->assertEquals([1, 2, 3], $collection->toArray());
    }

    public function testCanAccessAsArray(): void
    {
        $collection = new Collection([1, 2, 3]);
        
        $this->assertEquals(1, $collection[0]);
        $this->assertEquals(2, $collection[1]);
        $this->assertTrue(isset($collection[0]));
    }

    public function testCanIterate(): void
    {
        $collection = new Collection([1, 2, 3]);
        $items = [];
        
        foreach ($collection as $item) {
            $items[] = $item;
        }
        
        $this->assertEquals([1, 2, 3], $items);
    }
}

