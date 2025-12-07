<?php

declare(strict_types=1);

namespace Tests\Unit;

use BareMetalPHP\Http\Response;
use Tests\TestCase;

class ResponseTest extends TestCase
{
    public function testCanCreateResponse(): void
    {
        $response = new Response('Hello World', 200);
        
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('Hello World', $response->getBody());
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testCanSetHeaders(): void
    {
        $response = new Response('Test', 200, [
            'Content-Type' => 'application/json',
            'X-Custom' => 'value'
        ]);
        
        $headers = $response->getHeaders();
        
        $this->assertEquals('application/json', $headers['Content-Type']);
        $this->assertEquals('value', $headers['X-Custom']);
    }

    public function testCanSendResponse(): void
    {
        $response = new Response('Hello', 200, ['X-Test' => 'value']);
        
        // Capture output
        ob_start();
        $response->send();
        $output = ob_get_clean();
        
        $this->assertEquals('Hello', $output);
        $this->assertEquals(200, http_response_code());
    }
}

