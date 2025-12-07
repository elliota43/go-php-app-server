<?php

declare(strict_types=1);

// -------------------------------------------------------------
// ERROR HANDLING
// -------------------------------------------------------------
$stderr = fopen("php://stderr", "wb");

// Set up error handler to write to stderr
set_error_handler(function ($severity, $message, $file, $line) use ($stderr) {
    fwrite($stderr, "PHP Error [{$severity}]: {$message} in {$file}:{$line}\n");
    return false;
});

// Set up exception handler
set_exception_handler(function ($exception) use ($stderr) {
    fwrite($stderr, "PHP Fatal Error: " . $exception->getMessage() . "\n");
    fwrite($stderr, "Stack trace:\n" . $exception->getTraceAsString() . "\n");
    exit(1);
});

// -------------------------------------------------------------
// LOAD FRAMEWORK (persistent)
// -------------------------------------------------------------
try {
    $bootstrap = require __DIR__ . '/bootstrap_app.php';
    if (!isset($bootstrap['kernel'])) {
        fwrite($stderr, "worker: bootstrap_app.php did not return 'kernel' key\n");
        exit(1);
    }
    $kernel = $bootstrap['kernel'];
} catch (\Throwable $e) {
    fwrite($stderr, "worker: bootstrap failed: " . $e->getMessage() . "\n");
    fwrite($stderr, "Stack trace:\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

try {
    require __DIR__ . '/bridge.php';
} catch (\Throwable $e) {
    fwrite($stderr, "worker: bridge.php load failed: " . $e->getMessage() . "\n");
    exit(1);
}

// -------------------------------------------------------------
// WORKER LOOP
// -------------------------------------------------------------
$stdin  = fopen("php://stdin",  "rb");
$stdout = fopen("php://stdout", "wb");
// $stderr already opened above

while (true) {
    // ----- 1. Read 4-byte length header -----
    $lenData = fread($stdin, 4);

    // No data yet - idle, keep waiting
    if ($lenData === '' || $lenData === false) {
        usleep(1000); // 1ms sleep to avoid busy loop
        continue;
    }

    // Partial header is a protocol error
    if (strlen($lenData) < 4) {
        fwrite($stderr, "worker: partial length header (got " . strlen($lenData) . " bytes)\n");
        break;
    }

    $lengthArr = unpack("Nlen", $lenData);
    $length    = $lengthArr['len'] ?? 0;

    if ($length <= 0) {
        fwrite($stderr, "worker: non-positive payload length: {$length}\n");
        continue;
    }

    // ----- 2. Read JSON payload of given length -----
    $json = '';
    $remaining = $length;

    while (strlen($json) < $length) {
        $chunk = fread($stdin, $remaining);
        if ($chunk === '' || $chunk === false) {
            fwrite($stderr, "worker: failed to read full request payload\n");
            continue 2; // go back to top of while(true)
        }
        $json      .= $chunk;
        $remaining -= strlen($chunk);
    }

    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite($stderr, "worker: invalid JSON payload: " . json_last_error_msg() . "\n");
        continue;
    }

    // ----- 3. Pass through BareMetalPHP kernel -----
    try {
        $result = handle_bridge_request($payload, $kernel);
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

    // ----- 4. Package response for Go -----
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

    // OPTIONAL: debug to see exactly what Go receives
    // fwrite($stderr, "worker outJson: " . $outJson . "\n");

    $outLen = pack("N", strlen($outJson));

    fwrite($stdout, $outLen);
    fwrite($stdout, $outJson);
    fflush($stdout);
}
