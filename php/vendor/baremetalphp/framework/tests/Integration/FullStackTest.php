<?php

declare(strict_types=1);

namespace Tests\Integration;

use BareMetalPHP\Application;
use BareMetalPHP\Database\Connection;
use BareMetalPHP\Database\Model;
use BareMetalPHP\Http\Kernel;
use BareMetalPHP\Http\Request;
use BareMetalPHP\Routing\Router;
use Tests\TestCase;
use Tests\Feature\TestKernel;

class FullStackTest extends TestCase
{
    protected bool $needsDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->createTable('users', <<<SQL
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                created_at TEXT,
                updated_at TEXT
            )
        SQL);
    }

    public function testFullRequestResponseCycle(): void
    {
        // Set up router
        $router = new Router($this->app);
        $router->get('/users/{id}', function(int $id) {
            $user = TestUser::find($id);
            if (!$user) {
                return new \BareMetalPHP\Http\Response('Not Found', 404);
            }
            return new \BareMetalPHP\Http\Response(json_encode($user->toArray()), 200, [
                'Content-Type' => 'application/json'
            ]);
        });
        $this->app->instance(Router::class, $router);
        
        // Create test user
        $user = TestUser::create(['name' => 'John', 'email' => 'john@example.com']);
        
        // Create kernel and handle request (use TestKernel to avoid middleware output)
        $kernel = new TestKernel($this->app, $router);
        $request = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/users/' . $user->id
        ], [], []);
        
        $response = $kernel->handle($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertEquals('John', $data['name']);
    }

    public function testServiceProviderIntegration(): void
    {
        // This test doesn't need database, but we inherit from TestCase
        // which tries to clean up. Let's skip database cleanup for this test
        $provider = new class($this->app) extends \BareMetalPHP\Support\ServiceProvider {
            public function register(): void
            {
                $this->app->singleton('test.service', fn() => 'test-value');
            }
        };
        
        $this->app->registerProviders([get_class($provider)]);
        $this->app->boot();
        
        $service = $this->app->make('test.service');
        $this->assertEquals('test-value', $service);
    }
}

class TestUser extends Model
{
    protected static string $table = 'users';
}

