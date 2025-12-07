<?php

declare(strict_types=1);


use BareMetalPHP\Application;
use BareMetalPHP\Http\Kernel;
use BareMetalPHP\Http\Request;

// load composer

require __DIR__ . '/vendor/autoload.php';

// -------------------------------------------------
// Boot the BareMetalPHP Application once (persistent in worker)
// -------------------------------------------------

// Base path = parent of the /php folder
$basePath = dirname(__DIR__);

// create framework application
$app = new Application($basePath);

// load service providers 
if (function_exists('config') && config('app.providers')) {
    $app->registerProviders(config('app.providers'));
}

// some apps use auto-detection / defaults, so ensure providers are loaded:
foreach([
    BareMetalPHP\Providers\ConfigServiceProvider::class,
    BareMetalPHP\Providers\RoutingServiceProvider::class,
    BareMetalPHP\Providers\HttpServiceProvider::class,
    BareMetalPHP\Providers\ViewServiceProvider::class,
    BareMetalPHP\Providers\DatabaseServiceProvider::class,
    BareMetalPHP\Providers\LoggingServiceProvider::class,
    BareMetalPHP\Providers\AppServiceProvider::class,
] as $provider) {
    if (class_exists($provider)) {
        $app->registerProviders([$provider]);
    }
}

// boot service providers
$app->boot();

// resolve http kernel
$kernel = $app->make(Kernel::class);

// return to worker.php
return [
    'app'    => $app,
    'kernel' => $kernel,
];