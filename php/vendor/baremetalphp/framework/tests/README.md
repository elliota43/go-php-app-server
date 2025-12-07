# Framework Test Suite

This directory contains comprehensive unit, feature, and integration tests for the PHP framework using PHPUnit.

## Test Structure

- **Unit Tests** (`tests/Unit/`): Test individual components in isolation
  - `ApplicationTest.php` - Service container functionality
  - `RouterTest.php` - Routing and named routes
  - `RequestTest.php` - HTTP request handling
  - `ResponseTest.php` - HTTP response handling
  - `ModelTest.php` - ActiveRecord ORM functionality
  - `ModelRelationshipsTest.php` - Model relationships (hasMany, belongsTo, etc.)
  - `QueryBuilderTest.php` - Database query builder
  - `CollectionTest.php` - Collection utility class
  - `ConfigTest.php` - Configuration management
  - `LogTest.php` - Logging system
  - `ServiceProviderTest.php` - Service provider registration
  - `HelperFunctionsTest.php` - Global helper functions

- **Feature Tests** (`tests/Feature/`): Test complete features end-to-end
  - `HttpKernelTest.php` - HTTP kernel request handling
  - `ViewTest.php` - View rendering and templating
  - `EnvTest.php` - Environment variable loading

- **Integration Tests** (`tests/Integration/`): Test full application stack
  - `FullStackTest.php` - Complete request/response cycle

## Running Tests

### Run all tests:
```bash
./vendor/bin/phpunit
```

### Run specific test suite:
```bash
./vendor/bin/phpunit tests/Unit
./vendor/bin/phpunit tests/Feature
./vendor/bin/phpunit tests/Integration
```

### Run specific test file:
```bash
./vendor/bin/phpunit tests/Unit/ApplicationTest.php
```

### Run with coverage:
```bash
./vendor/bin/phpunit --coverage-html coverage/
```

## Test Configuration

The test suite is configured via `phpunit.xml`:
- Bootstrap file: `tests/bootstrap.php`
- Test suites: Unit, Feature, Integration
- Source code coverage: `src/` directory
- Environment: Testing mode with debug disabled

## Test Base Class

All tests extend `Tests\TestCase` which provides:
- Application instance setup
- Optional database setup (in-memory SQLite)
- Helper methods for common test operations

## Writing New Tests

1. Create a test class extending `Tests\TestCase`
2. Use descriptive test method names starting with `test`
3. Use PHPUnit assertions (`assertEquals`, `assertTrue`, etc.)
4. For database tests, set `protected bool $needsDatabase = true;`
5. Use `setUp()` and `tearDown()` for test fixtures

Example:
```php
<?php

namespace Tests\Unit;

use Tests\TestCase;

class MyComponentTest extends TestCase
{
    public function testCanDoSomething(): void
    {
        $result = doSomething();
        
        $this->assertTrue($result);
    }
}
```

## Test Coverage

The test suite covers:
- ✅ Service Container (binding, resolving, singletons)
- ✅ Routing (GET, POST, parameters, named routes)
- ✅ HTTP Request/Response handling
- ✅ Models (CRUD, relationships, accessors/mutators)
- ✅ Query Builder (WHERE, ORDER BY, LIMIT, etc.)
- ✅ Collections (map, filter, pluck, etc.)
- ✅ Configuration management
- ✅ Logging system
- ✅ Service Providers
- ✅ Helper Functions
- ✅ Environment variables
- ✅ View rendering
- ✅ HTTP Kernel

## Notes

- Tests use in-memory SQLite databases for database operations
- Some tests may require specific environment setup
- Helper functions that call `die()` (like `abort()`) cannot be fully tested

