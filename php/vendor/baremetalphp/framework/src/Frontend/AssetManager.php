<?php

declare(strict_types=1);

namespace BareMetalPHP\Frontend;

use BareMetalPHP\Support\Config;
use BareMetalPHP\Support\Env;

/**
 * Manages frontend assets with Vite integration
 */
class AssetManager
{
    protected ?array $manifest = null;
    protected bool $isDevelopment = false;
    protected ?string $devServerUrl = null;
    protected string $manifestPath;
    protected string $publicPath;

    public function __construct()
    {
        $this->isDevelopment = env('APP_ENV', 'production') === 'local' && $this->isViteDevServerRunning();
        $this->manifestPath = base_path('public/build/.vite/manifest.json');
        $this->publicPath = config('frontend.public_path', '/build');
        $this->devServerUrl = config('frontend.vite_server', 'http://localhost:5173');
    }

    /**
     * Check if Vite dev server is running
     */
    protected function isViteDevServerRunning(): bool
    {
        // Skip check in production
        if (env('APP_ENV', 'production') !== 'local') {
            return false;
        }
        
        $server = config('frontend.vite_server', 'http://localhost:5173');
        
        // Create stream context with timeout for fast failure
        $context = stream_context_create([
            'http' => [
                'timeout' => 0.5,
                'method' => 'HEAD',
                'ignore_errors' => true,
            ]
        ]);
        
        // Try to check if Vite dev server is running
        // Check the actual entry point file - if it returns content, server is running
        $entry = config('frontend.entry', 'resources/js/app.jsx');
        $testUrl = rtrim($server, '/') . '/' . ltrim($entry, '/');
        
        // Suppress warnings for faster failure
        $headers = @get_headers($testUrl, false, $context);
        
        return $headers !== false && isset($headers[0]) && (strpos($headers[0], '200') !== false || strpos($headers[0], '404') === false);
    }

    /**
     * Get asset URL for development or production
     */
    public function asset(string $path): string
    {
        // In development mode, always use Vite dev server
        if ($this->isDevelopment) {
            return $this->devServerUrl . '/' . ltrim($path, '/');
        }

        // If in local environment but dev server wasn't detected during construction,
        // check again and use dev server as fallback
        if (env('APP_ENV', 'production') === 'local') {
            if ($this->isViteDevServerRunning()) {
                return $this->devServerUrl . '/' . ltrim($path, '/');
            }
        }

        return $this->getProductionAsset($path);
    }

    /**
     * Get production asset URL from manifest
     */
    protected function getProductionAsset(string $path): string
    {
        $manifest = $this->getManifest();
        
        // Normalize path - remove leading slash
        $path = ltrim($path, '/');
        
        if (!isset($manifest[$path])) {
            // If manifest doesn't exist or entry not found, try dev server as fallback
            // This handles cases where build hasn't been run yet
            if (env('APP_ENV', 'production') === 'local') {
                // Always try dev server in local environment - it's safer to assume it's running
                // Even if the check fails, return the dev server URL and let the browser handle the error
                return $this->devServerUrl . '/' . $path;
            }
            
            // Fallback: Return relative path that will be resolved by browser
            // Don't use absolute URL with APP_URL as it might be wrong
            return $this->publicPath . '/' . $path;
        }

        $assetPath = $manifest[$path]['file'] ?? $path;
        // For production, use relative path from public directory
        return $this->publicPath . '/' . ltrim($assetPath, '/');
    }

    /**
     * Get CSS files for an entry point
     */
    public function css(string $entry): array
    {
        if ($this->isDevelopment) {
            return [];
        }

        $manifest = $this->getManifest();
        $entry = ltrim($entry, '/');
        
        if (!isset($manifest[$entry])) {
            return [];
        }

        return $manifest[$entry]['css'] ?? [];
    }

    /**
     * Generate Vite client script tag (for HMR)
     */
    public function viteClient(): string
    {
        if (!$this->isDevelopment) {
            return '';
        }

        $url = $this->devServerUrl . '/@vite/client';

        // When using React, inject React preamble
        $framework = Config::get('frontend.framework', null);

        if ($framework === 'react') {
            $reactPreamble = <<<HTML
            <script type="module">
                import RefreshRuntime from "{$this->devServerUrl}/@react-refresh";
                RefreshRuntime.injectIntoGlobalHook(window);
                window.\$RefreshReg\$ = () => {};
                window.\$RefreshSig\$ = () => (type) => type;
                window.__vite_plugin_react_preamble_installed__ = true;
            </script>
            HTML;
        }

        return $reactPreamble . '<script type="module" src="' . htmlspecialchars($url) . '"></script>';
    }

    /**
     * Generate script tag for an entry point
     */
    public function script(string $entry, array $attributes = []): string
    {
        $url = $this->asset($entry);
        $attrs = $this->buildAttributes(array_merge(['type' => 'module'], $attributes));
        
        return '<script' . $attrs . ' src="' . htmlspecialchars($url) . '"></script>';
    }

    /**
     * Generate link tags for CSS files
     */
    public function styles(string $entry): string
    {
        if ($this->isDevelopment) {
            return '';
        }

        $cssFiles = $this->css($entry);
        $html = '';

        foreach ($cssFiles as $css) {
            $url = asset($this->publicPath . '/' . ltrim($css, '/'));
            $html .= '<link rel="stylesheet" href="' . htmlspecialchars($url) . '">' . "\n";
        }

        return $html;
    }

    /**
     * Get manifest file contents
     */
    protected function getManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (!file_exists($this->manifestPath)) {
            $this->manifest = [];
            return $this->manifest;
        }

        $contents = file_get_contents($this->manifestPath);
        $this->manifest = json_decode($contents, true) ?? [];
        
        return $this->manifest;
    }

    /**
     * Build HTML attributes string
     */
    protected function buildAttributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $key => $value) {
            if ($value === true) {
                $html .= ' ' . htmlspecialchars($key);
            } elseif ($value !== false && $value !== null) {
                $html .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars((string)$value) . '"';
            }
        }
        return $html;
    }

    /**
     * Check if we're in development mode
     */
    public function isDev(): bool
    {
        return $this->isDevelopment;
    }
}

