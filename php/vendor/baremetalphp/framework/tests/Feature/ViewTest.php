<?php

declare(strict_types=1);

namespace Tests\Feature;

use BareMetalPHP\View\TemplateEngine;
use BareMetalPHP\View\View;
use Tests\TestCase;

class ViewTest extends TestCase
{
    protected string $viewPath;
    protected string $cachePath;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->viewPath = sys_get_temp_dir() . '/framework_test_views_' . uniqid();
        $this->cachePath = sys_get_temp_dir() . '/framework_test_cache_' . uniqid();
        
        if (!is_dir($this->viewPath)) {
            mkdir($this->viewPath, 0755, true);
        }
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
        
        View::setBasePath($this->viewPath);
        View::setCachePath($this->cachePath);
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (is_dir($this->viewPath)) {
            array_map('unlink', glob($this->viewPath . '/*'));
            rmdir($this->viewPath);
        }
        if (is_dir($this->cachePath)) {
            array_map('unlink', glob($this->cachePath . '/*'));
            rmdir($this->cachePath);
        }
        
        parent::tearDown();
    }

    public function testCanCompileSimpleView(): void
    {
        file_put_contents($this->viewPath . '/test.php', 'Hello {{ $name }}');
        
        $compiled = TemplateEngine::compile('Hello {{ $name }}');
        
        $this->assertStringContainsString('htmlspecialchars', $compiled);
        $this->assertStringContainsString('$name', $compiled);
    }

    public function testCanCompileIfStatement(): void
    {
        $template = '@if ($show) Hello @endif';
        $compiled = TemplateEngine::compile($template);
        
        $this->assertStringContainsString('if', $compiled);
    }

    public function testCanCompileForeachStatement(): void
    {
        $template = '@foreach ($items as $item) {{ $item }} @endforeach';
        $compiled = TemplateEngine::compile($template);
        
        $this->assertStringContainsString('foreach', $compiled);
    }

    public function testCanMakeView(): void
    {
        file_put_contents($this->viewPath . '/welcome.php', 'Hello {{ $name }}');
        
        $response = View::make('welcome', ['name' => 'John']);
        
        $this->assertInstanceOf(\BareMetalPHP\Http\Response::class, $response);
        $this->assertStringContainsString('Hello John', $response->getBody());
    }

    public function testViewEscapesHtml(): void
    {
        file_put_contents($this->viewPath . '/test.php', '{{ $content }}');
        
        $response = View::make('test', ['content' => '<script>alert("xss")</script>']);
        
        $this->assertStringNotContainsString('<script>', $response->getBody());
        $this->assertStringContainsString('&lt;script&gt;', $response->getBody());
    }

    public function testViewCanOutputRawHtml(): void
    {
        file_put_contents($this->viewPath . '/test.php', '{!! $content !!}');
        
        $response = View::make('test', ['content' => '<strong>Bold</strong>']);
        
        $this->assertStringContainsString('<strong>Bold</strong>', $response->getBody());
    }
}

