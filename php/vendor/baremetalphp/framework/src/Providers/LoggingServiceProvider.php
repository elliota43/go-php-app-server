<?php

declare(strict_types=1);

namespace BareMetalPHP\Providers;

use BareMetalPHP\Support\Log;
use BareMetalPHP\Support\ServiceProvider;

class LoggingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Set log path (can be overridden via environment)
        $logPath = $this->getLogPath();
        Log::setLogPath($logPath);
    }

    protected function getLogPath(): string
    {
        // Check for explicit environment variable first
        if (getenv('LOG_PATH')) {
            return getenv('LOG_PATH');
        }

        // Try to find the project root
        $projectRoot = $this->findProjectRoot();

        // Try multiple locations for logs (in order of priority)
        $possiblePaths = [
            // Project root (calculated)
            $projectRoot . '/storage/logs',
            // Current working directory (where script is run from)
            getcwd() . '/storage/logs',
            // Framework root (relative to this file) - for development/testing
            dirname(__DIR__, 2) . '/storage/logs',
        ];
        
        // Return the first path that exists, or create it
        foreach ($possiblePaths as $path) {
            // Create directory if it doesn't exist (for logs)
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            if (is_dir($path)) {
                return $path;
            }
        }
        
        // Default to project root if nothing found
        $defaultPath = $projectRoot . '/storage/logs';
        @mkdir($defaultPath, 0755, true);
        return $defaultPath;
    }

    protected function findProjectRoot(): string
    {
        // Start from current working directory
        $dir = getcwd();
        
        // Go up directories until we find composer.json, vendor/autoload.php, or storage/ directory
        $maxDepth = 10;
        $depth = 0;
        
        while ($depth < $maxDepth) {
            // Check for common project root markers
            if (file_exists($dir . '/composer.json') || 
                file_exists($dir . '/vendor/autoload.php') ||
                is_dir($dir . '/storage')) {
                return $dir;
            }
            
            $parent = dirname($dir);
            if ($parent === $dir) {
                // Reached filesystem root
                break;
            }
            $dir = $parent;
            $depth++;
        }
        
        // Fallback to current working directory
        return getcwd();
    }
}

