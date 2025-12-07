<?php

declare(strict_types=1);

// -------------------------------------------------------------
// LOAD FRAMEWORK (persistent)
// -------------------------------------------------------------
$bootstrap = require __DIR__ . '/bootstrap_app.php';
$kernel    = $bootstrap['kernel'];

require __DIR__ . '/bridge.php';

// -------------------------------------------------------------
// WORKER LOOP
// -------------------------------------------------------------
$stdin  = fopen("php://stdin",  "rb");
$stdout = fopen("php://stdout", "wb");
$stderr = fopen("php://stderr", "wb");

while (!feof($stdin)) {

    // ----- 1. Read 4-byte length header -----
    $lenData = fread($stdin, 4);
    if (!$lenData || strlen($lenData) < 4) {
        fwrite($stderr, "worker: invalid length header\n");
        break;
    }

    $length = unpack("N", $lenData)[1];
    if ($length <= 0) {
        fwrite($stderr, "worker: non-positive payload length\n");
        break;
    }

    // ----- 2. Read JSON payload of given length -----
    $json = fread($stdin, $length);
    if ($json === false) {
        fwrite($stderr, "worker: failed to read request payload\n");
        break;
    }

    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite($stderr, "worker: invalid JSON\n");
        continue;
    }

    // ----- 3. Pass through BareMetalPHP kernel -----
    try {
        $result = handle_bridge_request($payload, $kernel);
    } catch (\Throwable $e) {
        fwrite($stderr, "worker: unhandled exception " . $e->getMessage() . "\n");

        $result = [
            'status'  => 500,
            'headers' => ['Content-Type' => 'text/plain'],
            'body'    => "Internal Server Error",
        ];
    }

    // ----- 4. Package response for Go -----
    $response = [
        'id'      => $payload['id'] ?? null,
        'status'  => $result['status'],
        'headers' => $result['headers'],
        'body'    => $result['body'],
    ];

    $outJson = json_encode($response);
    $outLen  = pack("N", strlen($outJson));

    fwrite($stdout, $outLen);
    fwrite($stdout, $outJson);
    fflush($stdout);
}
