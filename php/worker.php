<?php

declare(strict_types=1);

// -------------------------------------------------------------
// ERROR HANDLING
// -------------------------------------------------------------
$stderr = fopen("php://stderr", "wb");

// Set up error handler to write to stderr
set_error_handler(function ($severity, $message, $file, $line) use ($stderr) {
    fwrite($stderr, "PHP Error [{$severity}]: {$message} in {$file}:{$line}\n");
    return false; // let PHP's internal error handling continue if needed
});

// Set up exception handler
set_exception_handler(function ($exception) use ($stderr) {
    fwrite($stderr, "PHP Fatal Error: " . $exception->getMessage() . "\n");
    fwrite($stderr, "Stack trace:\n" . $exception->getTraceAsString() . "\n");
    exit(1);
});

// -------------------------------------------------------------
// LOAD BRIDGE (which bootstraps the app on demand)
// -------------------------------------------------------------
try {
    require __DIR__ . '/bridge.php';
} catch (\Throwable $e) {
    fwrite($stderr, "worker: bridge.php load failed: " . $e->getMessage() . "\n");
    fwrite($stderr, "Stack trace:\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

// -------------------------------------------------------------
// HELPERS
// -------------------------------------------------------------

/**
 * Read exactly $length bytes from a stream or return null on failure.
 */
function worker_read_exact($stream, int $length): ?string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));
        if ($chunk === '' || $chunk === false) {
            return null;
        }
        $data .= $chunk;
    }
    return $data;
}

/**
 * Detect if the payload wants streaming based on X-Go-Stream: 1 header.
 */
function worker_wants_streaming(array $payload): bool
{
    $headers = $payload['headers'] ?? [];

    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) !== 'x-go-stream') {
            continue;
        }

        $headerValue = is_array($value) ? ($value[0] ?? null) : $value;
        if ((string) $headerValue === '1') {
            return true;
        }

        break;
    }

    return false;
}

// -------------------------------------------------------------
// WORKER LOOP
// -------------------------------------------------------------
$stdin  = fopen("php://stdin",  "rb");
$stdout = fopen("php://stdout", "wb");

while (true) {
    // ----- 1. Read 4-byte length header -----
    $lenData = fread($stdin, 4);

    if ($lenData === '' || $lenData === false) {
        // EOF or error, exit worker loop
        break;
    }

    if (strlen($lenData) < 4) {
        fwrite($stderr, "worker: partial length header (got " . strlen($lenData) . " bytes)\n");
        break;
    }

    $lengthArr = unpack("Nlen", $lenData);
    $length    = (int)($lengthArr['len'] ?? 0);

    if ($length <= 0 || $length > 10 * 1024 * 1024) {
        fwrite($stderr, "worker: invalid payload length: {$length}\n");
        continue;
    }

    // ----- 2. Read JSON payload of given length -----
    $json = worker_read_exact($stdin, $length);
    if ($json === null) {
        fwrite($stderr, "worker: failed to read full request payload\n");
        break;
    }

    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite($stderr, "worker: invalid JSON payload: " . json_last_error_msg() . "\n");
        continue;
    }

    // ----- 3. Decide streaming vs non-streaming -----
    $streaming = worker_wants_streaming($payload);

    if ($streaming) {
        // STREAMING MODE:
        // - bridge.php will emit length-prefixed frames using send_stream_frame()
        try {
            handle_bridge_request_streaming($payload);
        } catch (\Throwable $e) {
            fwrite($stderr, "worker: streaming exception " . $e->getMessage() . "\n");
            // Best-effort error frame
            if (function_exists('send_stream_frame')) {
                send_stream_frame([
                    'type'  => 'error',
                    'error' => 'Internal Server Error',
                ]);
            }
        }
        continue;
    }

    // ----- 4. Non-streaming mode: single response -----
    try {
        $result = handle_bridge_request($payload);
    } catch (\Throwable $e) {
        fwrite($stderr, "worker: unhandled exception " . $e->getMessage() . "\n");

        $result = [
            'status'  => 500,
            'headers' => ['Content-Type' => 'text/plain; charset=UTF-8'],
            'body'    => "Internal Server Error",
        ];
    }

    // ----- Normalize headers so JSON always encodes an object -----
    $headersArray = $result['headers'] ?? [];

    if (!is_array($headersArray)) {
        $headersArray = [];
    }

    // If it's an empty array, we want {} in JSON, not [].
    // json_encode((object)[]) => "{}"
    $headersObject = (object) $headersArray;

    // ----- 5. Package response for Go -----
    $response = [
        'id'      => $payload['id'] ?? null,
        'status'  => $result['status'] ?? 200,
        'headers' => $headersObject,
        'body'    => $result['body'] ?? '',
    ];

    $outJson = json_encode($response);
    if ($outJson === false) {
        fwrite($stderr, "worker: json_encode failed: " . json_last_error_msg() . "\n");
        continue;
    }

    $outLen = pack("N", strlen($outJson));

    fwrite($stdout, $outLen);
    fwrite($stdout, $outJson);
    fflush($stdout);
}
