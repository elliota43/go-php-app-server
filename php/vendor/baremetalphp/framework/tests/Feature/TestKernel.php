<?php

declare(strict_types=1);

namespace Tests\Feature;

use BareMetalPHP\Application;
use BareMetalPHP\Http\Kernel;
use BareMetalPHP\Routing\Router;

/**
 * Test kernel without middleware to avoid output during tests
 */
class TestKernel extends Kernel
{
    protected array $middleware = [];
}

