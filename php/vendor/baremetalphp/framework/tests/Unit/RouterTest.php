<?php

declare(strict_types=1);

namespace Tests\Unit;

use BareMetalPHP\Application;
use BareMetalPHP\Http\Request;
use BareMetalPHP\Http\Response;
use BareMetalPHP\Routing\Router;
use Tests\TestCase;

class RouterTest extends TestCase
{
    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router($this->app);
    }

    public function testCanRegisterGetRoute(): void
    {
        $this->router->get('/test', fn() => new Response('Hello'));
        
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'], [], []);
        $response = $this->router->dispatch($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello', $response->getBody());
    }

    public function testCanRegisterPostRoute(): void
    {
        $this->router->post('/test', fn() => new Response('Created', 201));
        
        $request = new Request([], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test'], [], []);
        $response = $this->router->dispatch($request);
        
        $this->assertEquals(201, $response->getStatusCode());
    }

    public function testCanRegisterRouteWithParameters(): void
    {
        $this->router->get('/users/{id}', function(int $id) {
            return new Response("User {$id}");
        });
        
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users/123'], [], []);
        $response = $this->router->dispatch($request);
        
        $this->assertEquals('User 123', $response->getBody());
    }

    public function testCanRegisterNamedRoute(): void
    {
        $route = $this->router->get('/users/{id}', fn() => new Response('test'));
        $route->name('users.show');
        
        $url = $this->router->route('users.show', ['id' => 42]);
        
        $this->assertEquals('/users/42', $url);
    }

    public function testNamedRouteThrowsExceptionIfNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Route 'nonexistent' is not defined");
        
        $this->router->route('nonexistent');
    }

    public function testReturns404ForNonExistentRoute(): void
    {
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/nonexistent'], [], []);
        $response = $this->router->dispatch($request);
        
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testCanRegisterControllerRoute(): void
    {
        $controller = new class {
            public function index(): Response
            {
                return new Response('Controller works');
            }
        };
        
        $this->app->instance('TestController', $controller);
        
        $this->router->get('/test', [$controller, 'index']);
        
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'], [], []);
        $response = $this->router->dispatch($request);
        
        $this->assertEquals('Controller works', $response->getBody());
    }

    public function testRouteParametersAreTypeCoerced(): void
    {
        $this->router->get('/posts/{id}', function(int $id) {
            return new Response("Post ID: {$id} (type: " . gettype($id) . ")");
        });
        
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/posts/123'], [], []);
        $response = $this->router->dispatch($request);
        
        $this->assertStringContainsString('Post ID: 123', $response->getBody());
        $this->assertStringContainsString('type: integer', $response->getBody());
    }
}

