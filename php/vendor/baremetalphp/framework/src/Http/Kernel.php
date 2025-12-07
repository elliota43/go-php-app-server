<?php

namespace BareMetalPHP\Http;

use BareMetalPHP\Routing\Router;
use BareMetalPHP\Application;
use BareMetalPHP\Http\Request;
use BareMetalPHP\Http\Response;
use BareMetalPHP\Exceptions\ErrorPageRenderer;
class Kernel 
{
    public function __construct(protected Application $app, protected Router $router)
    {}

    /**
     * List of global middleware.
     * @var string[]
     */
    protected array $middleware = [];

    public function handle(Request $request): Response
    {
        try {
            $pipeline = array_reduce(
                array_reverse($this->middleware),
                function (callable $next, string $middlewareClass) {
                    return function (Request $request) use ($next, $middlewareClass): Response {
                        $middleware = $this->app->make($middlewareClass);
                        return $middleware->handle($request, $next);
                    };
                },
                fn (Request $request): Response => $this->router->dispatch($request)
            );
            return $pipeline($request);
        } catch (\Throwable $e) {
            // Check if this is an API request
            $path = $request->getPath();
            $isApiRequest = str_starts_with($path, '/api/');
            
            if ($isApiRequest) {
                // Return JSON error for API requests
                $error = [
                    'error' => 'Internal Server Error',
                    'message' => $e->getMessage(),
                ];
                
                if (app_debug()) {
                    $error['file'] = $e->getFile();
                    $error['line'] = $e->getLine();
                    $error['trace'] = explode("\n", $e->getTraceAsString());
                }
                
                return new Response(
                    json_encode($error, JSON_PRETTY_PRINT),
                    500,
                    ['Content-Type' => 'application/json']
                );
            }
            
            // in debug mode, show pretty error page for non-API requests
            if (app_debug()) {
                $html = ErrorPageRenderer::render($e, $request, $this->app);
                return new Response($html, 500);
            }

            // production safe message
            return new Response('Internal Server Error', 500);
        }
    }
}