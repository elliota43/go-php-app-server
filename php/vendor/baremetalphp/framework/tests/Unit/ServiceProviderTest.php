<?php

declare(strict_types=1);

namespace Tests\Unit;

use BareMetalPHP\Application;
use BareMetalPHP\Support\ServiceProvider;
use Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function testServiceProviderCanBeRegistered(): void
    {
        $provider = new class($this->app) extends ServiceProvider {
            public function register(): void
            {
                $this->app->bind('test.service', fn() => 'test-value');
            }
        };
        
        $this->app->registerProviders([get_class($provider)]);
        
        $this->assertEquals('test-value', $this->app->make('test.service'));
    }

    public function testServiceProviderBootMethodIsCalled(): void
    {
        // Use a static variable to track boot state since we can't pass extra params
        $booted = false;
        
        $providerClass = get_class(new class($this->app) extends ServiceProvider {
            public static $booted = false;
            
            public function register(): void
            {
                // Empty
            }
            
            public function boot(): void
            {
                static::$booted = true;
            }
        });
        
        // Reset static variable
        $reflection = new \ReflectionClass($providerClass);
        $property = $reflection->getProperty('booted');
        $property->setAccessible(true);
        $property->setValue(null, false);
        
        $this->app->registerProviders([$providerClass]);
        $this->app->boot();
        
        $this->assertTrue($property->getValue());
    }

    public function testServiceProviderCanAccessApplication(): void
    {
        $provider = new class($this->app) extends ServiceProvider {
            public function register(): void
            {
                $this->app->bind('test', fn() => $this->app);
            }
        };
        
        $this->app->registerProviders([get_class($provider)]);
        
        $resolved = $this->app->make('test');
        $this->assertSame($this->app, $resolved);
    }
}

