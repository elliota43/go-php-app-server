<?php

declare(strict_types=1);

namespace Tests\Unit;

use BareMetalPHP\Support\Config;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Reset config
        $reflection = new \ReflectionClass(Config::class);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);
        $property->setValue(null, []);
        
        $loadedProperty = $reflection->getProperty('loaded');
        $loadedProperty->setAccessible(true);
        $loadedProperty->setValue(null, false);
    }

    public function testCanSetConfigValue(): void
    {
        Config::set('app.name', 'Test App');
        
        $this->assertEquals('Test App', Config::get('app.name'));
    }

    public function testCanGetConfigValue(): void
    {
        Config::set('database.host', 'localhost');
        Config::set('database.port', 3306);
        
        $this->assertEquals('localhost', Config::get('database.host'));
        $this->assertEquals(3306, Config::get('database.port'));
    }

    public function testCanGetConfigWithDefault(): void
    {
        $value = Config::get('nonexistent.key', 'default-value');
        
        $this->assertEquals('default-value', $value);
    }

    public function testCanCheckIfConfigExists(): void
    {
        Config::set('app.name', 'Test');
        
        $this->assertTrue(Config::has('app.name'));
        $this->assertFalse(Config::has('app.nonexistent'));
    }

    public function testCanGetAllConfig(): void
    {
        Config::set('app.name', 'Test');
        Config::set('app.env', 'local');
        
        $all = Config::all();
        
        $this->assertIsArray($all);
        $this->assertArrayHasKey('app', $all);
    }
}

