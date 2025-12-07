<?php

declare(strict_types=1);

use BareMetalPHP\Http\Request;
use BareMetalPHP\Http\Response;
use BareMetalPHP\Http\Kernel;

/**
 * Build the $_SERVER array BareMetalPHP expects
 */
function build_server_array(array $payload): array
{
    $method  = strtoupper($payload['method'] ?? 'GET');
    $uri     = $payload['path'] ?? '/';
    $headers = $payload['headers'] ?? [];
    $body    = $payload['body'] ?? '';

    $server = [
        'REQUEST_METHOD' => $method,
        'REQUEST_URI'    => $uri,
        'CONTENT_LENGTH' => strlen($body),
    ];

    // Map headers to PHP-style SERVER keys
    foreach ($headers as $name => $value) {
        $upper = strtoupper(str_replace('-', '_', $name));
        $server["HTTP_$upper"] = $value;

        if ($upper === 'CONTENT_TYPE') {
            $server['CONTENT_TYPE'] = $value;
        }
    }

    return $server;
}

/**
 * Convert Go → BareMetalPHP Request
 */
function make_baremetal_request(array $payload): Request
{
    $path    = $payload['path']    ?? '/';
    $body    = $payload['body']    ?? '';
    $headers = $payload['headers'] ?? [];

    // Build fake $_SERVER
    $server = build_server_array($payload);

    // Parse GET params
    $queryString = parse_url($path, PHP_URL_QUERY);
    $get = [];
    if ($queryString) {
        parse_str($queryString, $get);
    }

    // Parse POST form bodies
    $post = [];
    if (isset($headers['Content-Type']) &&
        str_starts_with($headers['Content-Type'], 'application/x-www-form-urlencoded')) {
        parse_str($body, $post);
    }

    return new Request(
        get: $get,
        post: $post,
        server: $server,
        cookies: [],
        files: []
    );
}

/**
 * BareMetalPHP kernel execution wrapper
 */
function handle_bridge_request(array $payload, Kernel $kernel): array
{
    //$path = $payload['path'] ?? '/';

    /* short-circuit root path to prove the pipeline
    if ($path === '/' || $path === '') {
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'text/plain; charset=UTF-8'],
            'body' => 'Hello from BareMetalPHP App Server via Go/PHP worker bridge!\n',
        ];
    }
    */
    $request = make_baremetal_request($payload);

    /** @var Response $response */
    $response = $kernel->handle($request);

    return [
        'status'  => $response->getStatusCode(),
        'headers' => $response->getHeaders(),
        'body'    => $response->getBody(),
    ];
}
