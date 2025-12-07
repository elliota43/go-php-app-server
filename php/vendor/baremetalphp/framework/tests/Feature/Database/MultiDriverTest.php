<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use BareMetalPHP\Database\Connection;
use BareMetalPHP\Database\ConnectionManager;
use BareMetalPHP\Database\Builder;
use BareMetalPHP\Support\Collection;
use Tests\TestCase;

class MultiDriverTest extends TestCase
{
    protected bool $needsDatabase = true;

    public function testQueryBuilderUsesDriverForSqlGeneration(): void
    {
        $connection = new Connection('sqlite::memory:');
        $pdo = $connection->pdo();
        
        // Create test table
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO users (name) VALUES ('John')");
        
        $builder = new Builder($pdo, 'users', null, $connection);
        $sql = $builder->toSql();
        
        // Should use driver's quoteIdentifier
        $this->assertStringContainsString('[users]', $sql);
    }

    public function testQueryBuilderHandlesLimitOffsetCorrectly(): void
    {
        $connection = new Connection('sqlite::memory:');
        $pdo = $connection->pdo();
        
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        for ($i = 1; $i <= 5; $i++) {
            $pdo->exec("INSERT INTO users (name) VALUES ('User {$i}')");
        }
        
        $builder = new Builder($pdo, 'users', null, $connection);
        $results = $builder->limit(2)->offset(1)->get();
        
        $this->assertEquals(2, $results->count());
    }

    public function testConnectionManagerCanSwitchConnections(): void
    {
        $manager = new ConnectionManager();
        
        $conn1 = new Connection('sqlite::memory:');
        $conn2 = new Connection('sqlite::memory:');
        
        $manager->addConnection('conn1', $conn1);
        $manager->addConnection('conn2', $conn2);
        $manager->setDefaultConnection('conn1');
        
        $this->assertSame($conn1, $manager->connection());
        $this->assertSame($conn2, $manager->connection('conn2'));
    }
}

