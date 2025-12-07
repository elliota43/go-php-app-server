<?php

declare(strict_types=1);

namespace BareMetalPHP\Database;

use BareMetalPHP\Database\Driver\DriverInterface;
use BareMetalPHP\Database\Driver\SqliteDriver;
use BareMetalPHP\Database\Driver\MysqlDriver;
use BareMetalPHP\Database\Driver\PostgresDriver;

class ConnectionManager
{
    protected array $connections = [];
    protected array $connectionConfigs = [];
    protected string $defaultConnection = 'default';
    protected array $drivers = [];

    public function __construct()
    {
        $this->registerDrivers();
    }

    /**
     * Register available database drivers
     */
    protected function registerDrivers(): void
    {
        $this->drivers = [
            'sqlite' => SqliteDriver::class,
            'mysql' => MysqlDriver::class,
            'pgsql' => PostgresDriver::class,
            'postgres' => PostgresDriver::class, // Alias
        ];
    }

    /**
     * Add a connection
     */
    public function addConnection(string $name, Connection $connection): void
    {
        $this->connections[$name] = $connection;
    }

    /**
     * Get a connection by name (lazy load if needed)
     */
    public function connection(?string $name = null): Connection
    {
        $name = $name ?? $this->defaultConnection;

        // If connection doesn't exist, try to create it from config
        if (!isset($this->connections[$name])) {
            if (isset($this->connectionConfigs[$name])) {
                $connection = $this->createConnection($this->connectionConfigs[$name]);
                $this->addConnection($name, $connection);
            } else {
                throw new \RuntimeException("Database connection [{$name}] not found.");
            }
        }

        return $this->connections[$name];
    }

    /**
     * Set the default connection name
     */
    public function setDefaultConnection(string $name): void
    {
        $this->defaultConnection = $name;
    }

    /**
     * Get the default connection name
     */
    public function getDefaultConnection(): string
    {
        return $this->defaultConnection;
    }

    /**
     * Create a connection from configuration
     */
    public function createConnection(array $config): Connection
    {
        $driverName = $config['driver'] ?? 'sqlite';
        
        if (!isset($this->drivers[$driverName])) {
            throw new \RuntimeException("Unsupported database driver: {$driverName}");
        }

        $driverClass = $this->drivers[$driverName];
        /** @var DriverInterface $driver */
        $driver = new $driverClass();

        $dsn = $driver->buildDsn($config);
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        $options = $config['options'] ?? [];

        $connection = new Connection($dsn, $username, $password, $options);
        $connection->setDriver($driver);

        return $connection;
    }

    /**
     * Get all registered connections
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Check if a connection exists
     */
    public function hasConnection(string $name): bool
    {
        return isset($this->connections[$name]);
    }

    /**
     * Set connection configurations for lazy loading
     */
    public function setConnectionConfigs(array $configs): void
    {
        $this->connectionConfigs = $configs;
    }
}

