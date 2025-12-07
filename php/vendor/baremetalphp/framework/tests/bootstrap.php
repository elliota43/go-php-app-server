<?php

declare(strict_types=1);

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load helper functions
if (file_exists(__DIR__ . '/../src/Support/helpers.php')) {
    require_once __DIR__ . '/../src/Support/helpers.php';
}

// Set up test environment
putenv('APP_ENV=testing');
putenv('APP_DEBUG=false');

