<?php

declare(strict_types=1);

namespace Tests\Unit;

use BareMetalPHP\Application;
use BareMetalPHP\Http\Response;
use BareMetalPHP\Support\Config;
use BareMetalPHP\Support\Env;
use Tests\TestCase;

class HelperFunctionsTest extends TestCase
{
    public function testDdFunctionExists(): void
    {
        $this->assertTrue(function_exists('dd'));
    }

    public function testDumpFunctionExists(): void
    {
        $this->assertTrue(function_exists('dump'));
    }

    public function testAbortFunction(): void
    {
        // abort() calls die(), so we can't test it normally
        // Just verify the function exists
        $this->assertTrue(function_exists('abort'));
        
        // We can't actually call abort() in a test as it terminates execution
    }

    public function testRedirectFunction(): void
    {
        $response = redirect('/dashboard');
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(302, $response->getStatusCode());
        $this->assertArrayHasKey('Location', $response->getHeaders());
    }

    public function testConfigFunction(): void
    {
        // Use Config::set method
        Config::set('test.key', 'test-value');
        
        $value = config('test.key');
        
        $this->assertEquals('test-value', $value);
    }

    public function testConfigFunctionWithDefault(): void
    {
        $value = config('nonexistent.key', 'default-value');
        
        $this->assertEquals('default-value', $value);
    }

    public function testEnvFunction(): void
    {
        putenv('TEST_VAR=test-value');
        
        $value = env('TEST_VAR');
        
        $this->assertEquals('test-value', $value);
    }

    public function testEnvFunctionWithDefault(): void
    {
        $value = env('NONEXISTENT_VAR', 'default');
        
        $this->assertEquals('default', $value);
    }

    public function testEnvFunctionTypeCasting(): void
    {
        putenv('TEST_BOOL=true');
        putenv('TEST_INT=42');
        putenv('TEST_FLOAT=3.14');
        
        $this->assertTrue(env('TEST_BOOL'));
        $this->assertIsInt(env('TEST_INT'));
        $this->assertEquals(42, env('TEST_INT'));
        $this->assertIsFloat(env('TEST_FLOAT'));
    }

    public function testLogFunctionsExist(): void
    {
        $this->assertTrue(function_exists('log_info'));
        $this->assertTrue(function_exists('log_error'));
        $this->assertTrue(function_exists('log_warning'));
    }

    public function testAssetFunction(): void
    {
        putenv('APP_URL=http://localhost:9003');
        
        $url = asset('css/style.css');
        
        $this->assertStringContainsString('css/style.css', $url);
    }

    public function testUrlFunction(): void
    {
        putenv('APP_URL=http://localhost:9003');
        
        $url = url('/dashboard');
        
        $this->assertEquals('http://localhost:9003/dashboard', $url);
    }

    public function testViewFunction(): void
    {
        // Set up view paths for the test
        $viewPath = sys_get_temp_dir() . '/test_view_' . uniqid();
        $cachePath = sys_get_temp_dir() . '/test_cache_' . uniqid();
        mkdir($viewPath, 0755, true);
        mkdir($cachePath, 0755, true);
        
        \BareMetalPHP\View\View::setBasePath($viewPath);
        \BareMetalPHP\View\View::setCachePath($cachePath);
        
        // Create a test view file
        file_put_contents($viewPath . '/test.php', 'Hello {{ $name }}');
        
        $response = view('test', ['name' => 'John']);
        
        $this->assertInstanceOf(Response::class, $response);
        
        // Cleanup
        unlink($viewPath . '/test.php');
        rmdir($viewPath);
        array_map('unlink', glob($cachePath . '/*'));
        rmdir($cachePath);
    }

    public function testResponseFunction(): void
    {
        $response = response('Hello', 200, ['X-Custom' => 'value']);
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('Hello', $response->getBody());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testJsonResponseFunction(): void
    {
        $response = json_response(['status' => 'success']);
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertArrayHasKey('Content-Type', $response->getHeaders());
        $this->assertEquals('application/json', $response->getHeaders()['Content-Type']);
    }

    public function testRouteFunction(): void
    {
        Application::setInstance($this->app);
        
        $router = new \BareMetalPHP\Routing\Router($this->app);
        $router->get('/test/{id}', fn() => new Response('test'))->name('test');
        $this->app->instance(\BareMetalPHP\Routing\Router::class, $router);
        
        $url = route('test', ['id' => 42]);
        
        $this->assertEquals('/test/42', $url);
    }
}

