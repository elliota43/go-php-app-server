<?php

declare(strict_types=1);

namespace BareMetalPHP\Providers;

use BareMetalPHP\Application;
use BareMetalPHP\Routing\Router;
use BareMetalPHP\Support\ServiceProvider;
use BareMetalPHP\Support\Env;

class RoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Router::class, function (Application $app) {
            $router = new Router($app);

            // Load routes from web.php by default
            $routesFile = $this->getRoutesFile();
            
            if (file_exists($routesFile)) {
                $define = require $routesFile;
                $define($router);
            } else {
                // In debug mode, throw exception if routes file not found
                if (Env::get('APP_DEBUG', false)) {
                    throw new \RuntimeException(
                        "Routes file not found: {$routesFile}\n" .
                        "Checked paths:\n" .
                        "  - " . $routesFile . "\n" .
                        "Current working directory: " . getcwd() . "\n" .
                        "Project root (calculated): " . $this->findProjectRoot()
                    );
                }
            }

            return $router;
        });
    }

    protected function getRoutesFile(): string
    {
        // Check for explicit environment variable first
        if (getenv('ROUTES_FILE')) {
            return getenv('ROUTES_FILE');
        }

        // Try to find the project root by looking for common markers
        // This handles the case where getcwd() might be the public directory when serving via PHP built-in server
        $projectRoot = $this->findProjectRoot();
        
        // Try multiple locations for routes file (in order of priority)
        $possiblePaths = [
            // Project root (calculated)
            $projectRoot . '/routes/web.php',
            // Current working directory (where script is run from)
            getcwd() . '/routes/web.php',
            // Framework root (relative to this file) - for development/testing
            dirname(__DIR__, 2) . '/routes/web.php',
        ];
        
        // Return the first path that exists
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        // Default to project root if nothing found (will be checked in register())
        return $projectRoot . '/routes/web.php';
    }

    protected function findProjectRoot(): string
    {
        // Start from current working directory
        $dir = getcwd();
        
        // Go up directories until we find composer.json, vendor/autoload.php, or routes/ directory
        $maxDepth = 10;
        $depth = 0;
        
        while ($depth < $maxDepth) {
            // Check for common project root markers
            if (file_exists($dir . '/composer.json') || 
                file_exists($dir . '/vendor/autoload.php') ||
                is_dir($dir . '/routes')) {
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

