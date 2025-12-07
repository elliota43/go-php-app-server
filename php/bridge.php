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
    $server = [];

    $method = $payload['method'] ?? 'GET';
    $path = $payload['path'] ?? '/';

    $server['REQUEST_METHOD'] = $method;
    $server['REQUEST_URI'] = $path;
    $server['SCRIPT_NAME'] = $path;
    $server['PHP_SELF'] = $path;

    $headers = $payload['headers'] ?? [];

    // Map headers to PHP-style SERVER keys
    foreach ($headers as $name => $value) {

        // Headers from Go are map[string][]string, so $value may be an array.
        if (is_array($value)) {
            $valueString = implode(', ', $value);
        } else {
            $valueString = (string) $value;
        }

        $normalized = strtolower($name);

        // Host -> SERVER_NAME / HTTP_HOST
        if ($normalized === 'host') {
            $server['HTTP_HOST'] = $valueString;
            $server['SERVER_NAME'] = $valueString;
            continue;
        }

        // Special-case content headers (PHP uses CONTENT_*)
        if ($normalized === 'content-type') {
            $server['CONTENT_TYPE'] = $valueString;
            continue;
        }

        if ($normalized === 'content-length') {
            $server['CONTENT_LENGTH'] = $valueString;
            continue;
        }

        // Everything else -> HTTP_FOO_BAR style
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $server[$key] = $valueString;
    }

    return $server;
}

/**
 * Convert Go → BareMetalPHP Request
 */
function make_baremetal_request(array $payload): Request
{
    // Build SERVER-style array first (REQUEST_METHOD, REQUEST_URI, HTTP_*, etc.)
    $server = build_server_array($payload);

    // Raw body from Go payload
    $body = $payload['body'] ?? '';
    $uri = $payload['uri'] ?? '/';


    // ---- Initialize everything so we never pass null ----
    $get     = [];
    $post    = [];
    $cookies = [];
    $files   = [];

    // ---- GET: parse query string from the URI ----
    $parts = parse_url($uri);
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $get);
    }

    // ---- POST: only for standard form content-types ----
    $contentType = $server['CONTENT_TYPE'] ?? '';

    if (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
        parse_str($body, $post);
    } else if (str_starts_with($contentType, 'multipart/form-data')) {
        // Real multipart parsing is non-trivial here.
        // for now we leave $post / $files empty in Go mode.
        $post = [];
        $files = [];
    }

    // ---- Cookies: parse from HTTP_COOKIE header ----
    $cookieHeader = $server['HTTP_COOKIE'] ?? '';
    if ($cookieHeader !== '') {
        foreach (explode(';', $cookieHeader) as $cookiePart) {
            $cookiePart = trim($cookiePart);
    
            // Skip empty or malformed segments
            if ($cookiePart === '' || !str_contains($cookiePart, '=')) {
                continue;
            }
    
            [$name, $value] = explode('=', $cookiePart, 2);
    
            $name  = trim((string) $name);
            $value = trim((string) $value);
    
            if ($name === '') {
                continue;
            }
    
            $cookies[$name] = urldecode($value);
        }
    }
    

    // ---- Build the framework Request object ----

    return Request::fromParts($server, $body, $get, $post, $cookies, $files);
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
