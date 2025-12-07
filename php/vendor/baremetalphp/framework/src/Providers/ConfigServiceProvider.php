<?php

declare(strict_types=1);

namespace BareMetalPHP\Providers;

use BareMetalPHP\Support\Config;
use BareMetalPHP\Support\Env;
use BareMetalPHP\Support\ServiceProvider;

class ConfigServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Load .env file first (before config)
        $this->loadEnvironmentFile();
        
        // Set config path (can be overridden via environment)
        $configPath = $this->getConfigPath();
        Config::setConfigPath($configPath);
    }

    public function boot(): void
    {
        // Load configuration files
        Config::load();
    }

    protected function loadEnvironmentFile(): void
    {
        // Try multiple locations for .env file (in order of priority)
        $possiblePaths = [
            // Current working directory (where script is run from) - highest priority
            getcwd() . '/.env',
            // Framework root (relative to this file)
            dirname(__DIR__, 2) . '/.env',
        ];
        
        // Only load the first .env file found
        foreach ($possiblePaths as $envPath) {
            if (file_exists($envPath)) {
                Env::load($envPath);
                break;
            }
        }
        
        // Also check for .env.local in the same locations (can override .env)
        $possibleLocalPaths = [
            getcwd() . '/.env.local',
            dirname(__DIR__, 2) . '/.env.local',
        ];
        
        foreach ($possibleLocalPaths as $envLocalPath) {
            if (file_exists($envLocalPath)) {
                // .env.local can override .env values
                Env::load($envLocalPath, true);
            }
        }
    }

    protected function getConfigPath(): string
    {
        // Check for explicit environment variable first
        if (Env::get('CONFIG_PATH')) {
            return Env::get('CONFIG_PATH');
        }

        // Try to find the project root
        $projectRoot = $this->findProjectRoot();

        // Try multiple locations for config (in order of priority)
        $possiblePaths = [
            // Project root (calculated)
            $projectRoot . '/config',
            // Current working directory (where script is run from)
            getcwd() . '/config',
            // Framework root (relative to this file) - for development/testing
            dirname(__DIR__, 2) . '/config',
        ];
        
        // Return the first path that exists, or default to project root
        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }
        
        // Default to project root if nothing found
        return $projectRoot . '/config';
    }

    protected function findProjectRoot(): string
    {
        // Start from current working directory
        $dir = getcwd();
        
        // Go up directories until we find composer.json, vendor/autoload.php, or config/ directory
        $maxDepth = 10;
        $depth = 0;
        
        while ($depth < $maxDepth) {
            // Check for common project root markers
            if (file_exists($dir . '/composer.json') || 
                file_exists($dir . '/vendor/autoload.php') ||
                is_dir($dir . '/config')) {
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

