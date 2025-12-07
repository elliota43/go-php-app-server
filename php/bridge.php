<?php

declare(strict_types=1);

use BareMetalPHP\Http\Request;
use BareMetalPHP\Http\Response;
use BareMetalPHP\Http\Kernel;
use BareMetalPHP\Application;
use BareMetalPHP\Routing\Router;
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

        // go sends map[string][]string, so $value may be an array
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
    $parts = parse_url($uri);
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $get);
    }
    $post    = [];
    $files   = [];

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
    $cookies = [];
    $cookieHeader = $server['HTTP_COOKIE'] ?? '';
    if ($cookieHeader !== '') {
        foreach (explode(';', $cookieHeader) as $cookiePart) {
            $cookiePart = trim($cookiePart);

            // Skip empty or malformed segments
            if ($cookiePart === '' || !str_contains($cookiePart, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $cookiePart, 2);

            $name = trim((string) $name);
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
 * Kernel singleton (so we don't re-bootstrap on every request).
 * 
 * Assumes bootstrap/app.php returns an Application instance
 */
function get_kernel(): Kernel
{
    static $kernel = null;

    if ($kernel !== null) {
        return $kernel;
    }

    // Adjust the path if bootstrap_app.php lives somewhere else
    $bootstrap = require __DIR__ .'/bootstrap_app.php';

    if (!is_array($bootstrap) || !isset($bootstrap['kernel'])) {
        throw new \RuntimeException('bootstrap_app.php must return [\'kernel\' => Kernel] in its array.');
    }

    $kernel = $bootstrap['kernel'];

    return $kernel;
}
/**
 * BareMetalPHP kernel execution wrapper
 */
function handle_bridge_request(array $payload, $kernel = null): array
{
    // Allow older code to pass a Kernel explicitly,
    // but default to get_kernel() for the new worker.
    if ($kernel === null) {
        $kernel = get_kernel();
    }

    $request = make_baremetal_request($payload);

    /** @var Response $response */
    $response = $kernel->handle($request);

    return [
        'status'  => $response->getStatusCode(),
        'headers' => $response->getHeaders(),
        'body'    => $response->getBody(),
    ];
}


/**
 * ---- Streaming helpers (length-prefixed frames) ---
 */

 function send_stream_frame(array $frame): void
 {
    $json = json_encode($frame, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    $len = strlen($json);
    $hdr = pack('N', $len); // 4-byte big-endian length

    fwrite(STDOUT, $hdr . $json);
    fflush(STDOUT);
 }

 function stream_response_headers(int $status, array $headers = [], ?string $data = null): void
 {
    $frame = [
        'type' => 'headers',
        'status' => $status,
        'headers' => $headers,
    ];

    if ($data !== null) {
        $frame['data'] = $data;
    }
    send_stream_frame($frame);
 }

 function stream_response_chunk(string $data): void
 {
    $frame = [
        'type' => 'chunk',
        'data' => $data,
    ];

    send_stream_frame($frame);
 }

 function stream_response_end(): void
 {
    send_stream_frame(['type' => 'end']);
 }


 function handle_bridge_request_streaming(array $payload): void
 {
    $kernel = get_kernel();
    $request = make_baremetal_request($payload);

    /** @var Response $response  */
    $response = $kernel->handle($request);

    $status = $response->getStatusCode();
    $headers = $response->getHeaders();
    $body = $response->getBody();

    stream_response_headers($status, $headers, $body);
    stream_response_end();
 }