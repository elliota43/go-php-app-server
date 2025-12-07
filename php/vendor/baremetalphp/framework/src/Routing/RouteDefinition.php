<?php

declare(strict_types=1);

namespace BareMetalPHP\Routing;

class RouteDefinition
{
    protected array $middleware = [];

    public function __construct(
        protected Router $router,
        protected string $method,
        protected string $uri,
        protected mixed $action
    ) {}

    public function name(string $name): self
    {
        $this->router->setRouteName($name, $this->method, $this->uri);
        return $this;
    }

    public function middleware(string|array $middleware): self
    {
        $this->middleware = array_merge(
            $this->middleware,
            is_array($middleware) ? $middleware : [$middleware]
        );
        $this->router->setRouteMiddleware($this->method, $this->uri, $this->middleware);
        return $this;
    }
}