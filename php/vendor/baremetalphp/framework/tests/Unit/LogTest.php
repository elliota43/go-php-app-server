<?php

declare(strict_types=1);

namespace Tests\Unit;

use BareMetalPHP\Support\Log;
use Tests\TestCase;

class LogTest extends TestCase
{
    protected string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Disable debug mode to prevent error_log output during tests
        putenv('APP_DEBUG=false');
        
        $this->logPath = sys_get_temp_dir() . '/framework_test_logs';
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
        
        Log::setLogPath($this->logPath);
    }

    protected function tearDown(): void
    {
        // Clean up log files
        if (is_dir($this->logPath)) {
            array_map('unlink', glob($this->logPath . '/*'));
            rmdir($this->logPath);
        }
        
        parent::tearDown();
    }

    public function testCanLogInfoMessage(): void
    {
        Log::info('Test info message');
        
        $logFile = $this->logPath . '/log-' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);
        $this->assertStringContainsString('Test info message', file_get_contents($logFile));
    }

    public function testCanLogErrorMessage(): void
    {
        // error() always logs to error_log regardless of APP_DEBUG
        // Suppress stderr output for this test
        $stderr = fopen('php://stderr', 'w');
        $originalStderr = ini_set('error_log', '/dev/null');
        
        Log::error('Test error message');
        
        if ($originalStderr !== false) {
            ini_set('error_log', $originalStderr);
        }
        fclose($stderr);
        
        $logFile = $this->logPath . '/log-' . date('Y-m-d') . '.log';
        $this->assertStringContainsString('error: Test error message', file_get_contents($logFile));
    }

    public function testCanLogWithContext(): void
    {
        Log::info('User logged in', ['user_id' => 123, 'ip' => '127.0.0.1']);
        
        $logFile = $this->logPath . '/log-' . date('Y-m-d') . '.log';
        $content = file_get_contents($logFile);
        $this->assertStringContainsString('User logged in', $content);
        $this->assertStringContainsString('user_id', $content);
    }

    public function testCanLogDifferentLevels(): void
    {
        // critical() always logs to error_log regardless of APP_DEBUG
        // Suppress stderr output for this test
        $originalStderr = ini_set('error_log', '/dev/null');
        
        Log::debug('Debug message');
        Log::warning('Warning message');
        Log::critical('Critical message');
        
        if ($originalStderr !== false) {
            ini_set('error_log', $originalStderr);
        }
        
        $logFile = $this->logPath . '/log-' . date('Y-m-d') . '.log';
        $content = file_get_contents($logFile);
        
        $this->assertStringContainsString('debug: Debug message', $content);
        $this->assertStringContainsString('warning: Warning message', $content);
        $this->assertStringContainsString('critical: Critical message', $content);
    }
}

