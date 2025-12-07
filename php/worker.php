<?php

// Minimal debug worker: just echos back request info.

$stdin  = fopen("php://stdin", "rb");
$stdout = fopen("php://stdout", "wb");
$stderr = fopen("php://stderr", "wb");

while (!feof($stdin)) {
    // 1) Read 4-byte length header
    $lenData = fread($stdin, 4);
    if ($lenData === '' || $lenData === false) {
        // End of stream
        fwrite($stderr, "worker: got empty length header, exiting\n");
        break;
    }

    if (strlen($lenData) < 4) {
        fwrite($stderr, "worker: short length header (" . strlen($lenData) . " bytes)\n");
        break;
    }

    $lenArr = unpack("Nlen", $lenData);
    $len    = $lenArr['len'] ?? 0;

    if ($len <= 0) {
        fwrite($stderr, "worker: non-positive length $len\n");
        break;
    }

    // 2) Read JSON payload of that length
    $json = '';
    while (strlen($json) < $len) {
        $chunk = fread($stdin, $len - strlen($json));
        if ($chunk === '' || $chunk === false) {
            fwrite($stderr, "worker: stream ended while reading payload\n");
            break 2; // exit outer loop
        }
        $json .= $chunk;
    }

    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        fwrite($stderr, "worker: json_decode failed: " . json_last_error_msg() . "\n");
        continue;
    }

    $id      = $payload['id']      ?? null;
    $method  = $payload['method']  ?? 'GET';
    $path    = $payload['path']    ?? '/';
    $body    = $payload['body']    ?? '';
    $headers = $payload['headers'] ?? [];

    $bodyPreview = is_string($body) ? $body : json_encode($body);

    $responseBody = "Hello from PHP worker!\n"
        . "Method: $method\n"
        . "Path:   $path\n"
        . "Body:   $bodyPreview\n";

    $respPayload = [
        'id'      => $id,
        'status'  => 200,
        'headers' => [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ],
        'body'    => $responseBody,
    ];

    $outJson = json_encode($respPayload);
    if ($outJson === false) {
        fwrite($stderr, "worker: json_encode failed: " . json_last_error_msg() . "\n");
        break;
    }

    $outLen  = pack("N", strlen($outJson));

    fwrite($stdout, $outLen);
    fwrite($stdout, $outJson);
    fflush($stdout);
}
