# Bare Metal PHP Framework

A lightweight, educational PHP framework with service container, routing, ORM, migrations, and more.  

![License](https://img.shields.io/badge/license-MIT-green.svg)
![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue)
![Packagist Version](https://img.shields.io/packagist/v/baremetalphp/framework)
![Downloads](https://img.shields.io/packagist/dt/baremetalphp/framework)
![Stars](https://img.shields.io/github/stars/baremetalphp/framework?style=social)
![Code Size](https://img.shields.io/github/languages/code-size/baremetalphp/framework)

![Inspired By Laravel](https://img.shields.io/badge/inspired%20by-Laravel-ff2d20)
![Lightweight](https://img.shields.io/badge/framework-lightweight-success)


> [!CAUTION]
> This framework is *NOT PRODUCTION READY*.  This is a limited feature framework intended as a learning tool/playground for developers.   

## Features

- 🎯 **Service Container** - Dependency injection and service management
- 🛣️ **Routing** - Clean, simple routing with middleware support
- 🗄️ **ORM** - Active Record style ORM with relationships (hasOne, hasMany, belongsTo, belongsToMany)
- 📊 **Migrations** - Database version control and schema management
- 🎨 **Views** - Simple templating engine with blade-like syntax
- 🔐 **Authentication** - Built-in authentication helpers
- 🧪 **Testing** - PHPUnit test suite included
- ⚡ **CLI Tools** - Built-in console commands for common tasks

## Requirements

- PHP 8.0+
- PDO extension
- SQLite, MySQL, or PostgreSQL support

## Quick Start

### Creating a New Project

The easiest way to get started is to use the project skeleton:

```bash
composer create-project baremetalphp/baremetalphp [your-project]

cd [your-project]

php mini migrate
php mini serve
```

> Note: The framework defaults to a SQLite database, but you can set up a MySQL connection in `.env` (PostgreSQL is ~95% functional but not fully tested).





### Manual Setup

1. **Require the framework**:

```bash
composer require elliotanderson/phpframework
```

2. **Set up your application structure**:

```
my-app/
├── app/
│   ├── Http/
│   │   └── Controllers/
│   └── Models/
├── bootstrap/
│   └── app.php
├── config/
│   └── database.php
├── public/
│   └── index.php
├── routes/
│   └── web.php
└── composer.json
```

3. **Create a route** (`routes/web.php`):

```php
use BareMetalPHP\Routing\Router;
use BareMetalPHP\Http\Response;

return function (Router $router): void {
    $router->get('/', function () {
        return new Response('Hello, World!');
    });
};
```

4. **Bootstrap your application** (`bootstrap/app.php`):

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use BareMetalPHP\Application;

$app = new Application(__DIR__ . '/..');
$app->registerProviders([
    BareMetalPHP\Providers\ConfigServiceProvider::class,
    BareMetalPHP\Providers\DatabaseServiceProvider::class,
    BareMetalPHP\Providers\RoutingServiceProvider::class,
    BareMetalPHP\Providers\ViewServiceProvider::class,
]);

return $app;
```

5. **Create your entry point** (`public/index.php`):

```php
<?php

$app = require __DIR__ . '/../bootstrap/app.php';
$app->run();
```

## Usage Examples

### Routing

```php
$router->get('/users', [UserController::class, 'index']);
$router->post('/users', [UserController::class, 'store']);
$router->get('/users/{id}', [UserController::class, 'show']);
```

### Models

```php
use BareMetalPHP\Database\Model;

class User extends Model
{
    protected $table = 'users';
    
    // Relationships
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

// Usage
$user = User::find(1);
$posts = $user->posts;
```

### Database Migrations

```php
use BareMetalPHP\Database\Migration;

class CreateUsersTable extends Migration
{
    public function up($connection)
    {
        $this->createTable($connection, 'users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }
    
    public function down($connection)
    {
        $this->dropTable($connection, 'users');
    }
}
```

### Views

```php
use BareMetalPHP\View\View;

return View::make('welcome', [
    'name' => 'World'
]);
```

## CLI Commands

The framework includes a CLI tool (`mini`) with several commands:

- `php mini serve` - Start the development server
- `php mini migrate` - Run pending migrations
- `php mini migrate:rollback` - Rollback the last migration
- `php mini make:controller Name` - Create a new controller
- `php mini make:migration name` - Create a new migration

## Testing

```bash
composer test
# or
vendor/bin/phpunit
```

## Documentation

For detailed documentation, visit the [framework documentation](https://github.com/elliotanderson/phpframework).

## License

MIT License - see [LICENSE](LICENSE) file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

