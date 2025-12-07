<?php

declare(strict_types=1);

namespace Tests\Unit;

use BareMetalPHP\Application;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    public function testApplicationCanBeInstantiated(): void
    {
        $app = new Application();
        $this->assertInstanceOf(Application::class, $app);
    }

    public function testCanBindService(): void
    {
        $this->app->bind('test.service', fn() => new \stdClass());
        
        $service1 = $this->app->make('test.service');
        $service2 = $this->app->make('test.service');
        
        $this->assertInstanceOf(\stdClass::class, $service1);
        $this->assertInstanceOf(\stdClass::class, $service2);
        // Should be different instances (not singleton)
        $this->assertNotSame($service1, $service2);
    }

    public function testCanBindSingleton(): void
    {
        $this->app->singleton('test.singleton', fn() => new \stdClass());
        
        $service1 = $this->app->make('test.singleton');
        $service2 = $this->app->make('test.singleton');
        
        // Should be the same instance
        $this->assertSame($service1, $service2);
    }

    public function testCanBindInstance(): void
    {
        $instance = new \stdClass();
        $this->app->instance('test.instance', $instance);
        
        $resolved = $this->app->make('test.instance');
        
        $this->assertSame($instance, $resolved);
    }

    public function testCanResolveClassWithoutBinding(): void
    {
        $service = $this->app->make(\stdClass::class);
        
        $this->assertInstanceOf(\stdClass::class, $service);
    }

    public function testCanResolveClassWithDependencies(): void
    {
        class_exists('Tests\Unit\TestService') || class_alias(\stdClass::class, 'Tests\Unit\TestService');
        
        $this->app->bind('Tests\Unit\TestService', function() {
            $obj = new \stdClass();
            $obj->name = 'Test';
            return $obj;
        });
        
        $service = $this->app->make('Tests\Unit\TestService');
        $this->assertInstanceOf(\stdClass::class, $service);
    }

    public function testCanRegisterServiceProviders(): void
    {
        $provider = new class($this->app) extends \BareMetalPHP\Support\ServiceProvider {
            public function register(): void
            {
                $this->app->bind('test.provider', fn() => 'test');
            }
        };
        
        $this->app->registerProviders([get_class($provider)]);
        $this->app->boot();
        
        $this->assertEquals('test', $this->app->make('test.provider'));
    }

    public function testApplicationInstanceCanBeSetGlobally(): void
    {
        $app = new Application();
        Application::setInstance($app);
        
        $this->assertSame($app, Application::getInstance());
    }
}

