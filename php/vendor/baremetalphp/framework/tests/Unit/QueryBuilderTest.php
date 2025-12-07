<?php

declare(strict_types=1);

namespace Tests\Unit;

use BareMetalPHP\Database\Builder;
use BareMetalPHP\Database\Connection;
use BareMetalPHP\Database\Model;
use BareMetalPHP\Support\Collection;
use Tests\TestCase;

class QueryBuilderTest extends TestCase
{
    protected bool $needsDatabase = true;
    
    protected function getTestConnection(): Connection
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        // Create connection wrapper
        $connection = new Connection('sqlite::memory:');
        return $connection;
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->createTable('users', <<<SQL
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                age INTEGER,
                status TEXT
            )
        SQL);
    }

    public function testCanBuildBasicQuery(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        // Create a connection for the builder
        $connection = $this->getTestConnection();
        $builder = new Builder($pdo, 'users', null, $connection);
        
        $sql = $builder->toSql();
        
        // Should use driver's quoteIdentifier
        $this->assertStringContainsString('SELECT * FROM', $sql);
        $this->assertStringContainsString('users', $sql);
    }

    public function testCanAddWhereClause(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        $connection = $this->getTestConnection();
        $builder = new Builder($pdo, 'users', null, $connection);
        $builder->where('status', '=', 'active');
        
        $sql = $builder->toSql();
        
        $this->assertStringContainsString("WHERE", $sql);
        $this->assertStringContainsString("status", $sql);
    }

    public function testCanAddMultipleWhereClauses(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        $builder = new Builder($pdo, 'users', null, $this->getTestConnection());
        $builder->where('status', '=', 'active')
                ->where('age', '>', 18);
        
        $sql = $builder->toSql();
        
        $this->assertStringContainsString('status', $sql);
        $this->assertStringContainsString('age', $sql);
        $this->assertStringContainsString('AND', $sql);
    }

    public function testCanAddOrWhereClause(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        $builder = new Builder($pdo, 'users', null, $this->getTestConnection());
        $builder->where('status', '=', 'active')
                ->orWhere('status', '=', 'pending');
        
        $sql = $builder->toSql();
        
        $this->assertStringContainsString('OR', $sql);
        $this->assertStringContainsString('status', $sql);
    }

    public function testCanAddOrderBy(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        $builder = new Builder($pdo, 'users', null, $this->getTestConnection());
        $builder->orderBy('name', 'ASC');
        
        $sql = $builder->toSql();
        
        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringContainsString('name', $sql);
        $this->assertStringContainsString('ASC', $sql);
    }

    public function testCanAddLimit(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        $builder = new Builder($pdo, 'users', null, $this->getTestConnection());
        $builder->limit(10);
        
        $sql = $builder->toSql();
        
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function testCanAddOffset(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        $builder = new Builder($pdo, 'users', null, $this->getTestConnection());
        $builder->offset(20);
        
        $sql = $builder->toSql();
        
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    public function testCanExecuteQuery(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        $pdo->exec("INSERT INTO users (name, email) VALUES ('John', 'john@example.com')");
        
        $builder = new Builder($pdo, 'users', null, $this->getTestConnection());
        $results = $builder->get();
        
        $this->assertInstanceOf(Collection::class, $results);
        $this->assertEquals(1, $results->count());
    }

    public function testCanGetFirstResult(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        $pdo->exec("INSERT INTO users (name, email) VALUES ('John', 'john@example.com')");
        $pdo->exec("INSERT INTO users (name, email) VALUES ('Jane', 'jane@example.com')");
        
        $builder = new Builder($pdo, 'users', null, $this->getTestConnection());
        $first = $builder->first();
        
        $this->assertIsArray($first);
        $this->assertEquals('John', $first['name']);
    }

    public function testCanCountResults(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        $pdo->exec("INSERT INTO users (name, email) VALUES ('John', 'john@example.com')");
        $pdo->exec("INSERT INTO users (name, email) VALUES ('Jane', 'jane@example.com')");
        
        $builder = new Builder($pdo, 'users', null, $this->getTestConnection());
        $count = $builder->count();
        
        $this->assertEquals(2, $count);
    }

    public function testCanUseWhereInOperator(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        $pdo->exec("INSERT INTO users (name, email) VALUES ('John', 'john@example.com')");
        $pdo->exec("INSERT INTO users (name, email) VALUES ('Jane', 'jane@example.com')");
        $pdo->exec("INSERT INTO users (name, email) VALUES ('Bob', 'bob@example.com')");
        
        $builder = new Builder($pdo, 'users', null, $this->getTestConnection());
        $results = $builder->where('id', 'IN', [1, 2])->get();
        
        $this->assertEquals(2, $results->count());
    }

    public function testCanUseShorthandWhere(): void
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            $this->markTestSkipped('PDO not available');
        }
        
        $builder = new Builder($pdo, 'users', null, $this->getTestConnection());
        $builder->where('status', 'active'); // Shorthand for '='
        
        $sql = $builder->toSql();
        
        $this->assertStringContainsString('status', $sql);
    }
}

