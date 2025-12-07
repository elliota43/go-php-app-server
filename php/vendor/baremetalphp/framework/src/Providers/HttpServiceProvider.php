<?php

declare(strict_types=1);

namespace BareMetalPHP\Providers;

use BareMetalPHP\Application;
use BareMetalPHP\Http\Kernel;
use BareMetalPHP\Support\ServiceProvider;

class HttpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Kernel::class, function (Application $app) {
            return new Kernel(
                $app,
                $app->make(\BareMetalPHP\Routing\Router::class)
            );
        });
    }
}

