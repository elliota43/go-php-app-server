<?php

declare(strict_types=1);

namespace BareMetalPHP\Providers;

use BareMetalPHP\Frontend\AssetManager;
use BareMetalPHP\Frontend\SPAHelper;
use BareMetalPHP\Support\ServiceProvider;

/**
 * Optional service provider for frontend framework integration
 * Only loads if explicitly registered in bootstrap/providers.php
 */
class FrontendServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register AssetManager as singleton
        $this->app->singleton(AssetManager::class, function () {
            return new AssetManager();
        });

        // Register SPAHelper
        $this->app->singleton(SPAHelper::class, function () {
            return new SPAHelper(
                $this->app->make(AssetManager::class)
            );
        });
    }

    public function boot(): void
    {
        // Frontend service provider is ready to use
        // Assets and SPA helpers are now available
    }
}

