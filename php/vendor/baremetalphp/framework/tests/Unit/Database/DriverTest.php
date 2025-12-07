<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use BareMetalPHP\Database\Driver\SqliteDriver;
use BareMetalPHP\Database\Driver\MysqlDriver;
use BareMetalPHP\Database\Driver\PostgresDriver;
use Tests\TestCase;

class DriverTest extends TestCase
{
    public function testSqliteDriverQuotesIdentifiers(): void
    {
        $driver = new SqliteDriver();
        
        $this->assertEquals('[users]', $driver->quoteIdentifier('users'));
        // Note: quoteIdentifier doesn't split on dots - it quotes the whole string
        $this->assertEquals('[users.id]', $driver->quoteIdentifier('users.id'));
    }

    public function testMysqlDriverQuotesIdentifiers(): void
    {
        $driver = new MysqlDriver();
        
        $this->assertEquals('`users`', $driver->quoteIdentifier('users'));
        // Note: quoteIdentifier doesn't split on dots - it quotes the whole string
        $this->assertEquals('`users.id`', $driver->quoteIdentifier('users.id'));
    }

    public function testPostgresDriverQuotesIdentifiers(): void
    {
        $driver = new PostgresDriver();
        
        $this->assertEquals('"users"', $driver->quoteIdentifier('users'));
        // Note: quoteIdentifier doesn't split on dots - it quotes the whole string
        $this->assertEquals('"users.id"', $driver->quoteIdentifier('users.id'));
    }

    public function testSqliteDriverCompilesLimit(): void
    {
        $driver = new SqliteDriver();
        
        $this->assertEquals('LIMIT 10', $driver->compileLimit(10, null));
        $this->assertEquals('LIMIT 10 OFFSET 20', $driver->compileLimit(10, 20));
        $this->assertEquals(' OFFSET 20', $driver->compileLimit(null, 20));
    }

    public function testMysqlDriverCompilesLimit(): void
    {
        $driver = new MysqlDriver();
        
        $this->assertEquals('LIMIT 10', $driver->compileLimit(10, null));
        $this->assertEquals('LIMIT 20, 10', $driver->compileLimit(10, 20));
        $this->assertStringContainsString('OFFSET 20', $driver->compileLimit(null, 20));
    }

    public function testPostgresDriverCompilesLimit(): void
    {
        $driver = new PostgresDriver();
        
        $this->assertEquals('LIMIT 10', $driver->compileLimit(10, null));
        $this->assertEquals('LIMIT 10 OFFSET 20', $driver->compileLimit(10, 20));
        $this->assertEquals(' OFFSET 20', $driver->compileLimit(null, 20));
    }

    public function testDriversBuildDsn(): void
    {
        $sqlite = new SqliteDriver();
        $dsn = $sqlite->buildDsn(['database' => 'test.db']);
        $this->assertStringStartsWith('sqlite:', $dsn);
        
        $mysql = new MysqlDriver();
        $dsn = $mysql->buildDsn([
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'test',
            'charset' => 'utf8mb4'
        ]);
        $this->assertStringStartsWith('mysql:', $dsn);
        $this->assertStringContainsString('host=localhost', $dsn);
        $this->assertStringContainsString('dbname=test', $dsn);
        
        $pgsql = new PostgresDriver();
        $dsn = $pgsql->buildDsn([
            'host' => 'localhost',
            'port' => 5432,
            'database' => 'test'
        ]);
        $this->assertStringStartsWith('pgsql:', $dsn);
        $this->assertStringContainsString('host=localhost', $dsn);
        $this->assertStringContainsString('dbname=test', $dsn);
    }

    public function testDriversPrepareValues(): void
    {
        $sqlite = new SqliteDriver();
        $this->assertEquals(1, $sqlite->prepareValue(true));
        $this->assertEquals(0, $sqlite->prepareValue(false));
        
        $mysql = new MysqlDriver();
        $this->assertEquals(1, $mysql->prepareValue(true));
        $this->assertEquals(0, $mysql->prepareValue(false));
        
        $pgsql = new PostgresDriver();
        $this->assertTrue($pgsql->prepareValue(true));
        $this->assertFalse($pgsql->prepareValue(false));
    }

    public function testDriversGetAutoIncrementTypes(): void
    {
        $sqlite = new SqliteDriver();
        $this->assertStringContainsString('AUTOINCREMENT', $sqlite->getAutoIncrementType());
        
        $mysql = new MysqlDriver();
        $this->assertStringContainsString('AUTO_INCREMENT', $mysql->getAutoIncrementType());
        
        $pgsql = new PostgresDriver();
        $this->assertStringContainsString('SERIAL', $pgsql->getAutoIncrementType());
    }
}

