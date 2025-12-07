<?php

declare(strict_types=1);

namespace BareMetalPHP\Database;

use BareMetalPHP\Database\Driver\DriverInterface;
use BareMetalPHP\Database\Driver\SqliteDriver;
use PDO;
use PDOException;

class Connection
{
    protected PDO $pdo;
    protected ?DriverInterface $driver = null;

    public function __construct(string $dsn, ?string $user = null, ?string $password = null, array $options = [])
    {
        $defaults = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];

        $options = $options + $defaults;

        $this->pdo = new PDO($dsn, $user, $password, $options);
        
        // Auto-detect driver from DSN if not set
        if ($this->driver === null) {
            $this->autoDetectDriver($dsn);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Set the database driver
     */
    public function setDriver(DriverInterface $driver): void
    {
        $this->driver = $driver;
    }

    /**
     * Get the database driver
     */
    public function getDriver(): DriverInterface
    {
        if ($this->driver === null) {
            // Fallback to SQLite if no driver is set
            $this->driver = new SqliteDriver();
        }
        
        return $this->driver;
    }

    /**
     * Auto-detect driver from DSN
     */
    protected function autoDetectDriver(string $dsn): void
    {
        if (str_starts_with($dsn, 'sqlite:')) {
            $this->driver = new SqliteDriver();
        } elseif (str_starts_with($dsn, 'mysql:')) {
            $this->driver = new \BareMetalPHP\Database\Driver\MysqlDriver();
        } elseif (str_starts_with($dsn, 'pgsql:')) {
            $this->driver = new \BareMetalPHP\Database\Driver\PostgresDriver();
        } else {
            // Default to SQLite
            $this->driver = new SqliteDriver();
        }
    }
}