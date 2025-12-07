<?php

declare(strict_types=1);

namespace BareMetalPHP\Providers;

use BareMetalPHP\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register application-specific bindings here
    }

    public function boot(): void
    {
        // Boot application-specific services here
    }
}

