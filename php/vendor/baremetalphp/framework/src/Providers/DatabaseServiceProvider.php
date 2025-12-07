<?php

declare(strict_types=1);

namespace BareMetalPHP\Providers;

use BareMetalPHP\Database\Connection;
use BareMetalPHP\Database\ConnectionManager;
use BareMetalPHP\Database\Model as BaseModel;
use BareMetalPHP\Support\ServiceProvider;
use BareMetalPHP\Support\Config;

class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register ConnectionManager
        $this->app->singleton(ConnectionManager::class, function () {
            return new ConnectionManager();
        });

        // Register default connection
        $this->app->singleton(Connection::class, function () {
            $manager = $this->app->make(ConnectionManager::class);
            return $manager->connection();
        });
    }

    public function boot(): void
    {
        $manager = $this->app->make(ConnectionManager::class);
        
        // Load database configuration
        Config::load();
        $dbConfig = Config::get('database', []);
        
        $defaultConnection = $dbConfig['default'] ?? 'sqlite';
        $connections = $dbConfig['connections'] ?? [];
        
        // Store connection configs for lazy loading (don't create connections yet)
        // Only create the default connection immediately
        $manager->setConnectionConfigs($connections);
        $manager->setDefaultConnection($defaultConnection);
        
        // Only create the default connection immediately
        if (isset($connections[$defaultConnection])) {
            if (!$manager->hasConnection($defaultConnection)) {
                $connection = $manager->createConnection($connections[$defaultConnection]);
                $manager->addConnection($defaultConnection, $connection);
            }
            // Attach default connection to base Model for backward compatibility
            BaseModel::setConnection($manager->connection($defaultConnection));
        }
    }
}

