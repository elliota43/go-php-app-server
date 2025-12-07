<?php

declare(strict_types=1);

namespace BareMetalPHP\Frontend\Middleware;

use BareMetalPHP\Http\Request;
use BareMetalPHP\Http\Response;

/**
 * Middleware to proxy Vite dev server requests
 */
class ViteDevMiddleware
{
    protected string $devServerUrl;

    public function __construct()
    {
        $this->devServerUrl = config('frontend.vite_server', 'http://localhost:5173');
    }

    public function handle(Request $request, callable $next): Response
    {
        $path = $request->getPath();
        
        // Proxy Vite dev server requests
        if ($this->isViteAsset($path)) {
            return $this->proxyToVite($request);
        }

        return $next($request);
    }

    /**
     * Check if path is a Vite asset
     */
    protected function isViteAsset(string $path): bool
    {
        return str_starts_with($path, '/@vite/') ||
               str_starts_with($path, '/node_modules/') ||
               preg_match('#\.(js|jsx|ts|tsx|vue|css)$#', $path);
    }

    /**
     * Proxy request to Vite dev server
     */
    protected function proxyToVite(Request $request): Response
    {
        $url = $this->devServerUrl . $request->getPath();
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return new Response('Not Found', 404);
        }

        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        $contentType = $this->extractContentType($headers);
        
        return new Response($body, 200, [
            'Content-Type' => $contentType,
        ]);
    }

    /**
     * Extract Content-Type from headers
     */
    protected function extractContentType(string $headers): string
    {
        if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $matches)) {
            return trim($matches[1]);
        }
        
        return 'application/octet-stream';
    }
}

