<?php

declare(strict_types=1);

namespace BareMetalPHP;

use BareMetalPHP\Support\ServiceProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

class Application
{
    public const VERSION = '0.1.4';
    
    protected static ?Application $instance = null;

    protected array $bindings = [];

    protected array $singletons = [];

    protected array $instances = [];

    /**
     * @var ServiceProvider[]
     */
    protected array $serviceProviders = [];

    protected array $loadedProviders = [];

    protected bool $booted = false;

    /**
     * Cache for ReflectionClass objects to avoid repeated instantiation
     * @var array<string, ReflectionClass>
     */
    protected array $reflectionCache = [];

    /**
     * Cache for constructor ReflectionMethod objects
     * @var array<string, ReflectionMethod|null>
     */
    protected array $constructorCache = [];

    /**
     * Get the globally accessible application instance
     */
    public static function getInstance(): ?Application
    {
        return static::$instance;
    }

    /**
     * Set the globally accessible application instance
     */
    public static function setInstance(Application $app): void
    {
        static::$instance = $app;
    }

    public function debugBindings(): array
    {
        return [
            'bindings' => array_keys($this->bindings),
            'singletons' => array_keys($this->singletons),
            'instances' => array_keys($this->instances),
        ];
    }

    public function bind(string $abstract, \Closure|string $concrete): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'singleton' => false,
        ];
    }
    
    public function singleton(string $abstract, \Closure|string $concrete): void
    {
        $this->bindings[$abstract] = [
            'concrete' => $concrete,
            'singleton' => true,
        ];
    }
    
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }
    
    public function make(string $abstract): mixed
    {
        // if we already have a singleton instance
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // if it's bound
        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract]['concrete'];

            // closure binding
            if ($concrete instanceof \Closure) {
                $object = $concrete($this);
            } else {
                // string class name
                $object = $this->build($concrete);
            }

            if ($this->bindings[$abstract]['singleton']) {
                $this->instances[$abstract] = $object;
            }

            return $object;
        }

        // no binding: assume $abstract is a class name
        return $this->build($abstract);
    }

    protected function build(string $concrete): mixed
    {
        $reflector = $this->getReflectionClass($concrete);

        if (! $reflector->isInstantiable()) {
            throw new \RuntimeException("Class {$concrete} is not instantiable.");
        }

        $constructor = $this->getConstructor($reflector);

        if (! $constructor) {
            return new $concrete();
        }

        $dependencies = array_map(
            fn (ReflectionParameter $param) => $this->resolveParameter($param),
            $constructor->getParameters()
        );

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Get or create a cached ReflectionClass for a class name
     */
    protected function getReflectionClass(string $className): ReflectionClass
    {
        if (!isset($this->reflectionCache[$className])) {
            $this->reflectionCache[$className] = new ReflectionClass($className);
        }

        return $this->reflectionCache[$className];
    }

    /**
     * Get or create a cached constructor ReflectionMethod
     */
    protected function getConstructor(ReflectionClass $reflection): ?ReflectionMethod
    {
        $className = $reflection->getName();
        
        if (!array_key_exists($className, $this->constructorCache)) {
            $this->constructorCache[$className] = $reflection->getConstructor();
        }

        return $this->constructorCache[$className];
    }

    protected function resolveParameter(ReflectionParameter $param): mixed
    {
        $type = $param->getType();

        if (! $type || $type->isBuiltin()) {
            throw new \RuntimeException("Cannot resolve parameter {$param->getName()}");
        }

        return $this->make($type->getName());
    }

    public function registerProviders(array $providers): void
    {
        foreach ($providers as $provider) {
            $this->registerProvider($provider);
        }
    }

    protected function registerProvider(string $provider): void
    {
        if (isset($this->loadedProviders[$provider])) {
            return;
        }

        $instance = new $provider($this);
        $instance->register();

        $this->serviceProviders[] = $instance;
        $this->loadedProviders[$provider] = true;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->serviceProviders as $provider) {
            $provider->boot();
        }

        $this->booted = true;
    }

    public function getProviders(): array
    {
        return $this->serviceProviders;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }
}
