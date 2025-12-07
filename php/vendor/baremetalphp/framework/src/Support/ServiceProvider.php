<?php

declare(strict_types=1);

namespace BareMetalPHP\Support;

use BareMetalPHP\Application;

abstract class ServiceProvider
{
    protected Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    /**
     * Determine if the provider is deferred.
     */
    public function isDeferred(): bool
    {
        return false;
    }
}

