<?php

declare(strict_types=1);

namespace Tests;

use BareMetalPHP\Application;
use BareMetalPHP\Database\Connection;
use BareMetalPHP\Database\Model;
use PHPUnit\BareMetalPHP\TestCase as BaseTestCase;
use PDO;

abstract class TestCase extends BaseTestCase
{
    protected Application $app;

    protected bool $needsDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->app = new Application();
        Application::setInstance($this->app);
        
        // Set up in-memory database for tests that need it
        if ($this->needsDatabase) {
            $this->setUpDatabase();
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up - reset static PDO property directly
        if ($this->needsDatabase) {
            try {
                $reflection = new \ReflectionClass(Model::class);
                $property = $reflection->getProperty('pdo');
                $property->setAccessible(true);
                $property->setValue(null, null);
            } catch (\Exception $e) {
                // Ignore errors during cleanup
            }
        }
    }

    protected function setUpDatabase(): void
    {
        $connection = new Connection('sqlite::memory:');
        $pdo = $connection->pdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        Model::setConnection($connection);
    }

    protected function createTable(string $table, string $schema): void
    {
        $pdo = $this->getPdo();
        if ($pdo) {
            $pdo->exec($schema);
        }
    }

    protected function getPdo(): ?PDO
    {
        $reflection = new \ReflectionClass(Model::class);
        $property = $reflection->getProperty('pdo');
        $property->setAccessible(true);
        return $property->getValue();
    }

    protected function getBuilderPdo(): PDO
    {
        // Create a builder to get PDO
        $builder = new \BareMetalPHP\Database\Builder($this->getPdo(), 'test');
        $reflection = new \ReflectionClass($builder);
        $property = $reflection->getProperty('pdo');
        $property->setAccessible(true);
        return $property->getValue($builder);
    }

    protected function make(string $abstract): mixed
    {
        return $this->app->make($abstract);
    }
}

