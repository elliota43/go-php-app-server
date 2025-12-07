<?php

declare(strict_types=1);

namespace BareMetalPHP\Http;

class Request
{
    protected ?array $parsedBody = null;
    protected ?array $jsonData = null;

    public function __construct(
        protected array $get,
        protected array $post,
        protected array $server,
        protected array $cookies,
        protected array $files
    )
    {}

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_COOKIE, $_FILES);
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function uri(): string
    {
        // @todo refine to strip query string
        return $this->server['REQUEST_URI'] ?? '/';
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->get;
        }

        return $this->get[$key] ?? $default;
    }

    public function getMethod(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function getPath(): string
    {
        // Parse request URI and strip query string
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return $path ?: '/';
    }

    /**
     * Get all input data (JSON body, form data, or query string)
     */
    public function all(): array
    {
        $input = [];
        
        // Merge query parameters
        $input = array_merge($input, $this->get);
        
        // Add parsed body (JSON or form data)
        $body = $this->getParsedBody();
        $input = array_merge($input, $body);
        
        return $input;
    }

    /**
     * Get input value by key with optional default
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->all();
        }

        $all = $this->all();
        return $all[$key] ?? $default;
    }

    /**
     * Get JSON data if request is JSON, otherwise return default
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->jsonData === null) {
            $this->jsonData = $this->parseJsonBody();
        }

        if ($key === null) {
            return $this->jsonData;
        }

        return $this->jsonData[$key] ?? $default;
    }

    /**
     * Check if request expects JSON response
     */
    public function expectsJson(): bool
    {
        $accept = $this->server['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }

    /**
     * Check if request is JSON
     */
    public function isJson(): bool
    {
        $contentType = $this->server['CONTENT_TYPE'] ?? '';
        return str_contains($contentType, 'application/json');
    }

    /**
     * Get uploaded files
     */
    public function file(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->files;
        }

        return $this->files[$key] ?? null;
    }

    /**
     * Check if request has file upload
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    /**
     * Get header value
     */
    public function header(?string $key = null, mixed $default = null): mixed
    {
        $headers = $this->getHeaders();

        if ($key === null) {
            return $headers;
        }

        // Normalize header name
        $key = strtolower(str_replace('_', '-', $key));
        return $headers[$key] ?? $default;
    }

    /**
     * Get all headers
     */
    protected function getHeaders(): array
    {
        $headers = [];

        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($headerName)] = $value;
            }
        }

        // Add Content-Type and Content-Length if present
        if (isset($this->server['CONTENT_TYPE'])) {
            $headers['content-type'] = $this->server['CONTENT_TYPE'];
        }
        if (isset($this->server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $this->server['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * Parse request body (JSON or form data)
     */
    protected function getParsedBody(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }

        // Try JSON first
        if ($this->isJson()) {
            $json = $this->parseJsonBody();
            if ($json !== null) {
                $this->parsedBody = $json;
                return $this->parsedBody;
            }
        }

        // Fallback to POST data
        $this->parsedBody = $this->post;
        return $this->parsedBody;
    }

    /**
     * Parse JSON request body
     */
    protected function parseJsonBody(): ?array
    {
        $rawBody = file_get_contents('php://input');
        
        if (empty($rawBody)) {
            return null;
        }

        $data = json_decode($rawBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}