<?php

/**
 * Sign and send a report file to a Fleet endpoint (the stub by default):
 *   php tests/send.php tests/fixtures/report-v1.example.json [http://127.0.0.1:8787]
 * Gives the report a fresh report_id each time so it isn't treated as a duplicate.
 */

declare(strict_types=1);

require __DIR__ . '/stub-server/lib.php';

$file = $argv[1] ?? null;
$base = rtrim($argv[2] ?? 'http://127.0.0.1:8787', '/');
if ($file === null) {
    fwrite(STDERR, "Usage: php tests/send.php <report.json> [fleet-url]\n");
    exit(2);
}

// Decode as objects: decoding to arrays would turn an empty {} (e.g. unknowns) into [], which the schema rejects.
$report = json_decode((string) file_get_contents($file));
$report->report_id = sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
$report->observed_at = gmdate('Y-m-d\TH:i:s\Z');
$body = json_encode($report, JSON_UNESCAPED_SLASHES);

$timestamp = (string) time();
$nonce = bin2hex(random_bytes(16));

$context = stream_context_create(['http' => [
    'method' => 'POST',
    'ignore_errors' => true,
    'header' => implode("\r\n", [
        'Content-Type: application/json',
        'X-Fleet-Key: ' . STUB_KEY_ID,
        'X-Fleet-Timestamp: ' . $timestamp,
        'X-Fleet-Nonce: ' . $nonce,
        'X-Fleet-Signature: ' . sign($timestamp, $nonce, $body, STUB_SECRET),
    ]),
    'content' => $body,
]]);

$response = file_get_contents($base . '/api/v1/reports', false, $context);
echo ($http_response_header[0] ?? 'no response'), "\n", $response;
