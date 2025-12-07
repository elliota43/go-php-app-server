<?php

namespace BareMetalPHP\Http;

class Response
{
    public function __construct(
        protected string $content = '',
        protected int $status = 200,
        protected array $headers = []
    ){}

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value)
        {
            header($name . ': '.$value);
        }

        echo $this->content;
    }

    public static function make(string $content, int $status = 200, array $headers = []): self
    {
        return new self($content, $status, $headers);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->content;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}