<?php

declare(strict_types=1);

namespace Tests\Feature;

use BareMetalPHP\Application;
use BareMetalPHP\Http\Kernel;
use BareMetalPHP\Http\Request;
use BareMetalPHP\Http\Response;
use BareMetalPHP\Routing\Router;
use Tests\TestCase;
use Tests\Feature\TestKernel;

class HttpKernelTest extends TestCase
{
    protected Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();
        
        $router = new Router($this->app);
        $this->kernel = new TestKernel($this->app, $router);
    }

    public function testKernelCanHandleRequest(): void
    {
        $router = new Router($this->app);
        $router->get('/test', fn() => new Response('Hello World'));
        $this->app->instance(Router::class, $router);
        
        $kernel = new TestKernel($this->app, $router);
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'], [], []);
        
        $response = $kernel->handle($request);
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello World', $response->getBody());
    }

    public function testKernelReturns404ForNonExistentRoute(): void
    {
        $router = new Router($this->app);
        $kernel = new TestKernel($this->app, $router);
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/nonexistent'], [], []);
        
        $response = $kernel->handle($request);
        
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testKernelHandlesExceptionsInDebugMode(): void
    {
        putenv('APP_DEBUG=true');
        
        $router = new Router($this->app);
        $router->get('/error', fn() => throw new \RuntimeException('Test error'));
        $this->app->instance(Router::class, $router);
        
        $kernel = new TestKernel($this->app, $router);
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/error'], [], []);
        
        $response = $kernel->handle($request);
        
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertStringContainsString('RuntimeException', $response->getBody());
        
        putenv('APP_DEBUG=false');
    }

    public function testKernelHandlesExceptionsInProductionMode(): void
    {
        putenv('APP_DEBUG=false');
        
        $router = new Router($this->app);
        $router->get('/error', fn() => throw new \RuntimeException('Test error'));
        $this->app->instance(Router::class, $router);
        
        $kernel = new TestKernel($this->app, $router);
        $request = new Request([], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/error'], [], []);
        
        $response = $kernel->handle($request);
        
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('Internal Server Error', $response->getBody());
    }
}

