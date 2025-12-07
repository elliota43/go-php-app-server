<?php

declare(strict_types=1);

namespace BareMetalPHP\Routing;

use BareMetalPHP\Http\Request;
use BareMetalPHP\Http\Response;
use BareMetalPHP\Application;
use BareMetalPHP\Database\Model;
use ReflectionFunction;
use ReflectionMethod;

class Router
{
    use SPARouteHelper;

    /**
     * @var array<string, array<int, array{uri:string,regex:string,params:array,action:mixed}>>
     */
    protected array $routes = [];

    protected array $namedRoutes = [];

    /**
     * Route middleware mapping: 'method:uri' => ['MiddlewareClass']
     */
    protected array $routeMiddleware = [];

    /**
     * Cache for reflection objects to avoid repeated instantiation
     * @var array<string, ReflectionMethod|ReflectionFunction>
     */
    protected array $reflectionCache = [];

    public function __construct(
        protected Application $app
    ) {}

    public function get(string $uri, mixed $action): RouteDefinition
    {
        return $this->addRoute('GET', $uri, $action);
    }

    public function post(string $uri, mixed $action): RouteDefinition
    {
        return $this->addRoute('POST', $uri, $action);
    }

    public function put(string $uri, mixed $action): RouteDefinition
    {
        return $this->addRoute('PUT', $uri, $action);
    }

    public function delete(string $uri, mixed $action): RouteDefinition
    {
        return $this->addRoute('DELETE', $uri, $action);
    }
    
    public function setRouteName(string $name, string $method, string $uri): void
    {
        $this->namedRoutes[$name] = [$method, $uri];
    }

    public function route(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new \RuntimeException("Route '{$name}' is not defined");
        }

        [$method, $uri] = $this->namedRoutes[$name];

        foreach ($params as $key => $value) {
            $uri = str_replace('{' . $key . '}', (string)$value, $uri);
        }

        return $uri;
    }

    protected function addRoute(string $method, string $uri, mixed $action): RouteDefinition
    {
        [$regex, $paramNames] = $this->compileUri($uri);
        
        // Check if route has parameters (fast path optimization)
        $hasParameters = str_contains($uri, '{');

        $routeData = [
            'uri'    => $uri,
            'regex'  => $regex,
            'params' => $paramNames,
            'action' => $action,
            'hasParameters' => $hasParameters,
        ];

        // Initialize routes array for method if needed
        if (!isset($this->routes[$method])) {
            $this->routes[$method] = [];
        }
        
        // Store exact matches first for fast lookup
        if (!$hasParameters) {
            // Insert at beginning for faster matching
            array_unshift($this->routes[$method], $routeData);
        } else {
            $this->routes[$method][] = $routeData;
        }

        return new RouteDefinition($this, $method, $uri, $action);
    }

    protected function compileUri(string $uri): array
    {
        // e.g. "/hello/{name}" -> "#^/hello/([^/]+)$#"
        // Special case: "{path}" matches multiple segments for SPA catch-all
        $paramNames = [];

        $pattern = preg_replace_callback(
            '#\{([^}]+)\}#',
            function ($matches) use (&$paramNames, $uri) {
                $paramName = $matches[1];
                $paramNames[] = $paramName;
                
                // If param is "path", allow multiple segments for SPA routing
                if ($paramName === 'path') {
                    return '(.+)';
                }
                
                return '([^/]+)';
            },
            $uri
        );

        $regex = '#^' . $pattern . '$#';

        return [$regex, $paramNames];
    }

    public function dispatch(Request $request): Response
    {

        // Make the current Request available for Dependency Injection.
        // Any controller or middleware that type-hints BareMetalPHP\Http\Request
        // and is resolved via the container will now get THIS instance.
        $this->app->instance(Request::class, $request);
        $method = $request->getMethod();
        $path   = $request->getPath();

        if (!isset($this->routes[$method])) {
            // In debug mode, throw exception to get error page
            if (app_debug()) {
                $availableMethods = array_keys($this->routes);
                throw new \RuntimeException(
                    "No routes registered for {$method} method. Available methods: " . 
                    (empty($availableMethods) ? 'none' : implode(', ', $availableMethods)) .
                    "\nRequested path: {$path}"
                );
            }
            return new Response('Not Found', 404);
        }

        foreach ($this->routes[$method] as $route) {
            $matched = false;
            $params = [];

            // Fast path: exact string match for routes without parameters
            if (!($route['hasParameters'] ?? false)) {
                if ($route['uri'] === $path) {
                    $matched = true;
                }
            } else {
                // Slow path: regex matching for routes with parameters
                if (preg_match($route['regex'], $path, $matches)) {
                    // $matches[0] is the full match, so drop it
                    array_shift($matches);
                    $params = $this->extractRouteParams($route['params'], $matches);
                    $matched = true;
                }
            }

            if ($matched) {
                // Get middleware for this route
                $middleware = $this->getRouteMiddleware($method, $route['uri']);
                
                // Run middleware and then call action
                return $this->runRouteMiddleware($middleware, $request, function (Request $request) use ($route, $params) {
                    return $this->callAction($route['action'], $params);
                });
            }
        }

        // no matching route - in debug mode, throw exception to get error page
        if (app_debug()) {
            $registeredRoutes = array_map(fn($r) => $r['uri'], $this->routes[$method] ?? []);
            throw new \RuntimeException(
                "Route not found: {$method} {$path}\n" .
                "Registered routes for {$method}: " . 
                (empty($registeredRoutes) ? 'none' : "\n  - " . implode("\n  - ", $registeredRoutes))
            );
        }
        
        return new Response('Not Found', 404);
    }

    protected function callAction(mixed $action, array $routeParams = []): Response
    {
        // 1) "Controller@method" string
        if (is_string($action) && str_contains($action, '@')) {
            [$controller, $method] = explode('@', $action, 2);

            $fqcn = 'App\\Http\\Controllers\\' . $controller;

            $instance = $this->app->make($fqcn);

            $result = $this->callWithDependencies([$instance, $method], $routeParams);

            return $this->toResponse($result);
        }

        // 2) [HomeController::class, 'show'] type route actions
        if (is_array($action) && isset($action[0], $action[1]) && is_string($action[0])) {
            // Resolve controller through the container
            $instance = $this->app->make($action[0]);
            $method   = $action[1];

            $result = $this->callWithDependencies([$instance, $method], $routeParams);

            return $this->toResponse($result);
        }

        // 3) Any other callable:
        //     - closure: function (Request $r, ...) {}
        //     - [new HomeController(), 'show']
        if (is_callable($action)) {
            $result = $this->callWithDependencies($action, $routeParams);

            return $this->toResponse($result);
        }

        // fallback if nothing matched
        return new Response('Invalid route action', 500);
    }

    public function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_string($result)) {
            return new Response($result);
        }

        // very naive: just cast to string
        return new Response((string) $result);
    }

    /**
     * Get or create a cached reflection object for a callable
     */
    protected function getReflection(callable $callable): ReflectionMethod|ReflectionFunction
    {
        $cacheKey = $this->getCallableCacheKey($callable);
        
        if (isset($this->reflectionCache[$cacheKey])) {
            return $this->reflectionCache[$cacheKey];
        }

        if (is_array($callable)) {
            // [object, 'method'] or [class-string, 'method']
            $class = is_object($callable[0]) ? get_class($callable[0]) : $callable[0];
            $reflection = new ReflectionMethod($class, $callable[1]);
        } elseif (is_string($callable)) {
            // Function name - cacheable
            $reflection = new ReflectionFunction($callable);
        } else {
            // Closure - cannot cache effectively, create new each time
            $reflection = new ReflectionFunction($callable);
            return $reflection; // Don't cache closures
        }

        // Cache reflection for non-closures
        $this->reflectionCache[$cacheKey] = $reflection;
        return $reflection;
    }

    /**
     * Generate a cache key for a callable
     */
    protected function getCallableCacheKey(callable $callable): string
    {
        if (is_array($callable)) {
            $class = is_object($callable[0]) ? get_class($callable[0]) : $callable[0];
            return 'method:' . $class . '::' . $callable[1];
        }
        
        if (is_string($callable)) {
            return 'function:' . $callable;
        }
        
        // For closures, use a unique identifier (not cacheable effectively)
        return 'closure:' . spl_object_hash($callable);
    }

    protected function callWithDependencies(callable $callable, array $routeParams = []): mixed
    {
        $reflection = $this->getReflection($callable);

        $resolvedArgs = [];

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            $name = $param->getName();

            // 1) Class type-hint -> resolve via container
            if ($type && !$type->isBuiltin()) {
                $className = $type->getName();

                // 1a) Route model binding:
                // if the parameter type is a subclass of our base Model AND
                // there is a route param with the same name
                // hydrate the model from the route value
                if (is_subclass_of($className, Model::class) && array_key_exists($name, $routeParams)) {
                    $key = $routeParams[$name];

                    /**
                     * @var Model|null Model
                     */
                    $model = $className::find($key);

                    if ($model === null) {
                        // for now: hard fail; later you can map this to a 404
                        throw new \RuntimeException(
                            "Route model binding failed for {$className} with key '{$key}' (param \${name})"
                        );
                    }

                    $resolvedArgs[] = $model;
                    continue;
                }

                // 1b) otherwise, resolve via container
                $resolvedArgs[] = $this->app->make($className);
                continue;
            }

            // 2) Match by parameter name from route params: /posts/{id}
            if (array_key_exists($name, $routeParams)) {
                $value = $routeParams[$name];
                
                // Type coercion based on parameter type hint
                if ($type && $type->isBuiltin()) {
                    $typeName = $type->getName();
                    if ($typeName === 'int') {
                        $value = (int) $value;
                    } elseif ($typeName === 'float') {
                        $value = (float) $value;
                    } elseif ($typeName === 'bool') {
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    }
                }
                
                $resolvedArgs[] = $value;
                continue;
            }

            // 3) Default value
            if ($param->isDefaultValueAvailable()) {
                $resolvedArgs[] = $param->getDefaultValue();
                continue;
            }

            // 4) nothing worked -> fail
            throw new \RuntimeException(
                "Cannot resolve parameter \${$name} on {$reflection->getName()}"
            );
        }

        // finally call the controller/closure with the resolved arguments
        return $callable(...$resolvedArgs);
    }

    /**
     * Extract route parameters from regex matches
     */
    protected function extractRouteParams(array $paramNames, array $matches): array
    {
        $params = [];
        foreach ($paramNames as $index => $name) {
            $params[$name] = $matches[$index] ?? null;
        }
        return $params;
    }

    /**
     * Set middleware for a specific route
     */
    public function setRouteMiddleware(string $method, string $uri, array $middleware): void
    {
        $key = "{$method}:{$uri}";
        $this->routeMiddleware[$key] = $middleware;
    }

    /**
     * Get middleware for a route
     */
    public function getRouteMiddleware(string $method, string $uri): array
    {
        $key = "{$method}:{$uri}";
        return $this->routeMiddleware[$key] ?? [];
    }

    /**
     * Execute middleware for a route
     */
    protected function runRouteMiddleware(array $middleware, Request $request, callable $next): Response
    {
        if (empty($middleware)) {
            return $next($request);
        }

        $pipeline = array_reduce(
            array_reverse($middleware),
            function (callable $next, string $middlewareClass) {
                return function (Request $request) use ($next, $middlewareClass): Response {
                    $middleware = $this->app->make($middlewareClass);
                    return $middleware->handle($request, $next);
                };
            },
            $next
        );

        return $pipeline($request);
    }
}
