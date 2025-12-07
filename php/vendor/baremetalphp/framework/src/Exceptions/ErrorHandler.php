<?php

namespace BareMetalPHP\Exceptions;

use BareMetalPHP\Http\Response;

use Throwable;

class ErrorHandler
{
    public function handle(Throwable $e): Response
    {
        $debug = getenv('APP_DEBUG') == 'true';

        if ($debug) {
            return $this->debugResponse($e);
        }

        return new Response("Internal Server Error", 500);
    }

    protected function debugResponse(Throwable $e): Response
    {
        $html = "
            <h1>Unhandled Exception</h1>
            <p><strong>" . get_class($e) . ":</strong> {$e->getMessage()}</p>
            <p><strong>File:</strong> {$e->getFile()}</p>
            <p><strong>Line:</strong> {$e->getLine()}</p>
            <h2>Trace:</h2>
            <pre>{$e->getTraceAsString()}</pre>
        ";

        return new Response($html, 500, ['Content-Type' => 'text/html']);
    }
}