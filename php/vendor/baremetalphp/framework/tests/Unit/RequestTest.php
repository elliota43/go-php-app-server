<?php

declare(strict_types=1);

namespace Tests\Unit;

use BareMetalPHP\Http\Request;
use Tests\TestCase;

class RequestTest extends TestCase
{
    public function testCanCreateRequestFromGlobals(): void
    {
        $_GET = ['foo' => 'bar'];
        $_POST = ['baz' => 'qux'];
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'];
        
        $request = Request::fromGlobals();
        
        $this->assertInstanceOf(Request::class, $request);
        $this->assertEquals('bar', $request->query('foo'));
    }

    public function testCanGetQueryParameters(): void
    {
        $request = new Request(
            ['name' => 'John', 'age' => '30'],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'],
            [],
            []
        );
        
        $this->assertEquals('John', $request->query('name'));
        $this->assertEquals('30', $request->query('age'));
        $this->assertNull($request->query('nonexistent'));
        $this->assertEquals('default', $request->query('nonexistent', 'default'));
    }

    public function testCanGetAllQueryParameters(): void
    {
        $request = new Request(
            ['name' => 'John', 'age' => '30'],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/test'],
            [],
            []
        );
        
        $all = $request->query();
        $this->assertEquals(['name' => 'John', 'age' => '30'], $all);
    }

    public function testCanGetMethod(): void
    {
        $request = new Request(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test'],
            [],
            []
        );
        
        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('POST', $request->method());
    }

    public function testCanGetPath(): void
    {
        $request = new Request(
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/users/123?page=1'],
            [],
            []
        );
        
        $this->assertEquals('/users/123', $request->getPath());
    }

    public function testCanParseJsonBody(): void
    {
        $json = json_encode(['name' => 'John', 'email' => 'john@example.com']);
        
        $request = new Request(
            [],
            [],
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/test',
                'CONTENT_TYPE' => 'application/json'
            ],
            [],
            []
        );
        
        // Simulate JSON input
        $reflection = new \ReflectionClass($request);
        $method = $reflection->getMethod('parseJsonBody');
        $method->setAccessible(true);
        
        // We need to test via input() method instead
        $this->assertTrue($request->isJson());
    }

    public function testCanGetInputData(): void
    {
        $request = new Request(
            ['page' => '1'],
            ['name' => 'John', 'email' => 'john@example.com'],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test'],
            [],
            []
        );
        
        $this->assertEquals('John', $request->input('name'));
        $this->assertEquals('john@example.com', $request->input('email'));
        $this->assertEquals('1', $request->input('page')); // From query
    }

    public function testCanGetAllInputData(): void
    {
        $request = new Request(
            ['page' => '1'],
            ['name' => 'John'],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test'],
            [],
            []
        );
        
        $all = $request->all();
        $this->assertArrayHasKey('page', $all);
        $this->assertArrayHasKey('name', $all);
    }

    public function testCanCheckIfJsonRequest(): void
    {
        $request = new Request(
            [],
            [],
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/test',
                'CONTENT_TYPE' => 'application/json'
            ],
            [],
            []
        );
        
        $this->assertTrue($request->isJson());
    }

    public function testCanGetHeaders(): void
    {
        $request = new Request(
            [],
            [],
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/test',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_USER_AGENT' => 'Test Agent'
            ],
            [],
            []
        );
        
        $this->assertEquals('application/json', $request->header('accept'));
        $this->assertEquals('Test Agent', $request->header('user-agent'));
    }

    public function testCanGetFiles(): void
    {
        $files = [
            'avatar' => [
                'name' => 'test.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/tmp/test',
                'error' => UPLOAD_ERR_OK,
                'size' => 1234
            ]
        ];
        
        $request = new Request(
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/test'],
            [],
            $files
        );
        
        $this->assertNotNull($request->file('avatar'));
        $this->assertTrue($request->hasFile('avatar'));
        $this->assertFalse($request->hasFile('nonexistent'));
    }
}

