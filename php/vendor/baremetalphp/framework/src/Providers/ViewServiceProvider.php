<?php

declare(strict_types=1);

namespace BareMetalPHP\Providers;

use BareMetalPHP\Support\ServiceProvider;
use BareMetalPHP\View\View;

class ViewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Set view base path (can be overridden by config)
        $basePath = $this->getViewBasePath();
        $cachePath = $this->getViewCachePath();

        View::setBasePath($basePath);
        View::setCachePath($cachePath);
    }

    protected function getViewBasePath(): string
    {
        // Check for explicit environment variable first
        if (getenv('VIEW_BASE_PATH')) {
            return getenv('VIEW_BASE_PATH');
        }

        // Try to find the project root
        $projectRoot = $this->findProjectRoot();

        // Try multiple locations for views (in order of priority)
        $possiblePaths = [
            // Project root (calculated)
            $projectRoot . '/resources/views',
            // Current working directory (where script is run from)
            getcwd() . '/resources/views',
            // Framework root (relative to this file) - for development/testing
            dirname(__DIR__, 2) . '/resources/views',
        ];
        
        // Return the first path that exists, or default to project root
        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }
        
        // Default to project root if nothing found
        return $projectRoot . '/resources/views';
    }

    protected function getViewCachePath(): string
    {
        // Check for explicit environment variable first
        if (getenv('VIEW_CACHE_PATH')) {
            return getenv('VIEW_CACHE_PATH');
        }

        // Try to find the project root
        $projectRoot = $this->findProjectRoot();

        // Try multiple locations for view cache (in order of priority)
        $possiblePaths = [
            // Project root (calculated)
            $projectRoot . '/storage/views',
            // Current working directory (where script is run from)
            getcwd() . '/storage/views',
            // Framework root (relative to this file) - for development/testing
            dirname(__DIR__, 2) . '/storage/views',
        ];
        
        // Return the first path that exists, or create it
        foreach ($possiblePaths as $path) {
            // Create directory if it doesn't exist (for cache)
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            if (is_dir($path)) {
                return $path;
            }
        }
        
        // Default to project root if nothing found
        $defaultPath = $projectRoot . '/storage/views';
        @mkdir($defaultPath, 0755, true);
        return $defaultPath;
    }

    protected function findProjectRoot(): string
    {
        // Start from current working directory
        $dir = getcwd();

        // If we're in a 'public' directory, go up one level to the project root
        if (basename($dir) === 'public') {
            $parent = dirname($dir);
            // verify it looks like a project root
            if (file_exists($parent .'/composer.json') || file_exists($parent .'/vendor/autoload.php') || is_dir($parent .'/resources')) {
                return $parent;
            }
        }
        
        // Go up directories until we find composer.json, vendor/autoload.php, or resources/ directory
        $maxDepth = 10;
        $depth = 0;
        
        while ($depth < $maxDepth) {
            // Check for common project root markers
            if (file_exists($dir . '/composer.json') || 
                file_exists($dir . '/vendor/autoload.php') ||
                is_dir($dir . '/resources')) {
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

