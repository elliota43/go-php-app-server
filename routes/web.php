<?php

use BareMetalPHP\Routing\Router;

return function (Router $router) {
    $router->get('/', function() {
        return "Hello from BareMetalPHP real router!";
    });
};