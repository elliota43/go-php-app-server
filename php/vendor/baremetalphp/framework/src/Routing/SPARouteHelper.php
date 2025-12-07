<?php

declare(strict_types=1);

namespace BareMetalPHP\Routing;

/**
 * Helper trait for SPA route registration
 */
trait SPARouteHelper
{
    /**
     * Register an SPA route that catches all paths for a given prefix
     */
    public function spa(string $uri, mixed $action, string $method = 'GET'): RouteDefinition
    {
        // Convert /app/* to proper route patterns
        // e.g., /app/* matches /app, /app/users, /app/users/1, etc.
        if (str_ends_with($uri, '/*')) {
            $basePath = rtrim($uri, '/*');
            
            // Register base path (e.g., /app)
            $baseRoute = $this->addRoute($method, $basePath, $action);
            
            // Register catch-all pattern (e.g., /app/{path})
            $catchAllPattern = $basePath . '/{path}';
            $this->addRoute($method, $catchAllPattern, $action);
            
            return $baseRoute;
        }
        
        // If no /* pattern, just register as normal route
        return $this->addRoute($method, $uri, $action);
    }
}

