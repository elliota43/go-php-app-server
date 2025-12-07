<?php

declare(strict_types=1);

namespace Tests\Feature;

use BareMetalPHP\Support\Env;
use Tests\TestCase;

class EnvTest extends TestCase
{
    protected string $envFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envFile = sys_get_temp_dir() . '/.env.test';
        
        // Reset Env loaded state
        $reflection = new \ReflectionClass(\BareMetalPHP\Support\Env::class);
        $property = $reflection->getProperty('loaded');
        $property->setAccessible(true);
        $property->setValue(null, false);
        
        // Clear any existing env vars
        putenv('APP_NAME');
        putenv('APP_DEBUG');
        putenv('BOOL_TRUE');
        putenv('BOOL_FALSE');
        putenv('PORT');
        putenv('VERSION');
        putenv('ITEMS');
        putenv('EXISTS');
        unset($_ENV['APP_NAME'], $_ENV['APP_DEBUG'], $_ENV['BOOL_TRUE'], $_ENV['BOOL_FALSE'], 
              $_ENV['PORT'], $_ENV['VERSION'], $_ENV['ITEMS'], $_ENV['EXISTS']);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->envFile)) {
            unlink($this->envFile);
        }
        parent::tearDown();
    }

    public function testCanLoadEnvFile(): void
    {
        file_put_contents($this->envFile, "APP_NAME=Test App\nAPP_DEBUG=true");
        
        Env::load($this->envFile);
        
        $this->assertEquals('Test App', Env::get('APP_NAME'));
        $this->assertTrue(Env::get('APP_DEBUG'));
    }

    public function testCanCastBooleanValues(): void
    {
        file_put_contents($this->envFile, "BOOL_TRUE=true\nBOOL_FALSE=false");
        
        Env::load($this->envFile);
        
        $this->assertTrue(Env::get('BOOL_TRUE'));
        $this->assertFalse(Env::get('BOOL_FALSE'));
    }

    public function testCanCastIntegerValues(): void
    {
        file_put_contents($this->envFile, "PORT=8080");
        
        Env::load($this->envFile);
        
        $value = Env::get('PORT');
        $this->assertIsInt($value);
        $this->assertEquals(8080, $value);
    }

    public function testCanCastFloatValues(): void
    {
        file_put_contents($this->envFile, "VERSION=1.5");
        
        Env::load($this->envFile);
        
        $value = Env::get('VERSION');
        $this->assertIsFloat($value);
        $this->assertEquals(1.5, $value);
    }

    public function testCanCastArrayValues(): void
    {
        file_put_contents($this->envFile, "ITEMS=[item1,item2,item3]");
        
        Env::load($this->envFile);
        
        $value = Env::get('ITEMS');
        $this->assertIsArray($value);
        $this->assertEquals(['item1', 'item2', 'item3'], $value);
    }

    public function testCanHandleQuotedValues(): void
    {
        file_put_contents($this->envFile, 'APP_NAME="Test App"');
        
        Env::load($this->envFile);
        
        $this->assertEquals('Test App', Env::get('APP_NAME'));
    }

    public function testCanSkipComments(): void
    {
        file_put_contents($this->envFile, "# This is a comment\nAPP_NAME=TestApp");
        
        Env::load($this->envFile);
        
        $this->assertEquals('TestApp', Env::get('APP_NAME'));
        $this->assertFalse(Env::has('This'));
    }

    public function testCanCheckIfEnvExists(): void
    {
        file_put_contents($this->envFile, "EXISTS=true");
        
        Env::load($this->envFile);
        
        $this->assertTrue(Env::has('EXISTS'));
        $this->assertFalse(Env::has('NONEXISTENT'));
    }
}

