<?php

declare(strict_types= 1);

require __DIR__ .'/vendor/autoload';

/**
 * This file is responsible for:
 *  - Bootstrapping the framework once
 *  - Exposing a single function: handle_bridge_request(array $payload): array
 *    that turns as Go payload into a framework Response and normalizes it.
 * 
 */

