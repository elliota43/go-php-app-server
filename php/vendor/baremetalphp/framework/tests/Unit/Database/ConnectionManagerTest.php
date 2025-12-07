<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use BareMetalPHP\Database\Connection;
use BareMetalPHP\Database\ConnectionManager;
use Tests\TestCase;

class ConnectionManagerTest extends TestCase
{
    public function testCanCreateConnectionManager(): void
    {
        $manager = new ConnectionManager();
        
        $this->assertInstanceOf(ConnectionManager::class, $manager);
    }

    public function testCanAddConnection(): void
    {
        $manager = new ConnectionManager();
        $connection = new Connection('sqlite::memory:');
        
        $manager->addConnection('test', $connection);
        
        $this->assertTrue($manager->hasConnection('test'));
        $this->assertSame($connection, $manager->connection('test'));
    }

    public function testCanSetDefaultConnection(): void
    {
        $manager = new ConnectionManager();
        $connection1 = new Connection('sqlite::memory:');
        $connection2 = new Connection('sqlite::memory:');
        
        $manager->addConnection('conn1', $connection1);
        $manager->addConnection('conn2', $connection2);
        $manager->setDefaultConnection('conn2');
        
        $this->assertEquals('conn2', $manager->getDefaultConnection());
        $this->assertSame($connection2, $manager->connection());
    }

    public function testThrowsExceptionForNonExistentConnection(): void
    {
        $manager = new ConnectionManager();
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database connection [nonexistent] not found');
        
        $manager->connection('nonexistent');
    }

    public function testCanCreateConnectionFromConfig(): void
    {
        $manager = new ConnectionManager();
        
        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:'
        ];
        
        $connection = $manager->createConnection($config);
        
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertEquals('sqlite', $connection->getDriver()->getName());
    }

    public function testCanCreateMysqlConnectionFromConfig(): void
    {
        $manager = new ConnectionManager();
        
        $config = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'test',
            'username' => 'user',
            'password' => 'pass',
            'charset' => 'utf8mb4'
        ];
        
        // This test verifies the connection object is created with correct driver
        // It will fail to actually connect, but that's expected in a test environment
        try {
            $connection = $manager->createConnection($config);
            $this->assertInstanceOf(Connection::class, $connection);
            $this->assertEquals('mysql', $connection->getDriver()->getName());
        } catch (\PDOException $e) {
            // Expected - we don't have a real MySQL server in tests
            // Just verify the driver was set correctly before connection attempt
            $this->markTestSkipped('MySQL connection test skipped - no MySQL server available');
        }
    }

    public function testThrowsExceptionForUnsupportedDriver(): void
    {
        $manager = new ConnectionManager();
        
        $config = [
            'driver' => 'oracle',
            'database' => 'test'
        ];
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported database driver: oracle');
        
        $manager->createConnection($config);
    }
}

