<?php

declare(strict_types=1);

use BareMetalPHP\Application;
use BareMetalPHP\Http\Kernel;

// Load Composer autoload (framework lives in php/vendor)
require __DIR__ . '/vendor/autoload.php';

// Create the application container
$app = new Application();

// Optionally set global instance if your framework uses it
Application::setInstance($app);

// Manually register all the core service providers your framework ships with
$app->registerProviders([
    BareMetalPHP\Providers\ConfigServiceProvider::class,
    BareMetalPHP\Providers\RoutingServiceProvider::class,
    BareMetalPHP\Providers\HttpServiceProvider::class,
    BareMetalPHP\Providers\ViewServiceProvider::class,
    BareMetalPHP\Providers\DatabaseServiceProvider::class,
    BareMetalPHP\Providers\LoggingServiceProvider::class,
    BareMetalPHP\Providers\AppServiceProvider::class,
    BareMetalPHP\Providers\FrontendServiceProvider::class,
]);

// Boot providers (this will also cause RoutingServiceProvider to load routes/web.php)
$app->boot();

// Resolve the HTTP kernel
$kernel = $app->make(Kernel::class);

return [
    'app'    => $app,
    'kernel' => $kernel,
];
