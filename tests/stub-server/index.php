<?php

/**
 * Fleet API stub. Run with:
 *   php -S 127.0.0.1:8787 tests/stub-server/index.php
 * then set FLEET_URL=http://127.0.0.1:8787 and FLEET_ORG_KEY=fok_stub on the Craft site.
 *
 * Every accepted body is written to tests/stub-server/received/ for inspection.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}

function header_value(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : '';
}

function store(string $kind, string $body): void
{
    $dir = __DIR__ . '/received';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents(sprintf('%s/%s-%s.json', $dir, date('Ymd-His'), $kind), $body);
}

/** Checks the signing headers; responds 401 on any failure. */
function verify_signature(string $body): void
{
    $key = header_value('X-Fleet-Key');
    $timestamp = header_value('X-Fleet-Timestamp');
    $nonce = header_value('X-Fleet-Nonce');
    $signature = header_value('X-Fleet-Signature');

    if ($key !== STUB_KEY_ID) {
        respond(401, ['error' => 'unknown_key']);
    }
    if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > STUB_MAX_SKEW) {
        respond(401, ['error' => 'timestamp_out_of_range']);
    }
    if ($nonce === '' || !hash_equals(sign($timestamp, $nonce, $body, STUB_SECRET), $signature)) {
        respond(401, ['error' => 'bad_signature']);
    }

    $nonces = sys_get_temp_dir() . '/fleet-stub-nonces.txt';
    $seen = is_file($nonces) ? file($nonces, FILE_IGNORE_NEW_LINES) : [];
    if (in_array($nonce, $seen, true)) {
        respond(401, ['error' => 'nonce_reused']);
    }
    file_put_contents($nonces, $nonce . "\n", FILE_APPEND);
}

function require_valid_report(string $body): array
{
    $errors = validate_report($body);
    if ($errors) {
        respond(422, ['error' => 'schema_invalid', 'details' => $errors]);
    }
    return json_decode($body, true);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string) file_get_contents('php://input');

if ($method === 'POST' && $path === '/api/v1/register') {
    if (header_value('Authorization') !== 'Bearer ' . STUB_ORG_KEY) {
        respond(401, ['error' => 'bad_org_key']);
    }
    require_valid_report($body);
    store('register', $body);
    respond(201, [
        'installation_uuid' => '00000000-0000-4000-8000-000000000001',
        'key_id' => STUB_KEY_ID,
        'secret' => STUB_SECRET,
        'status' => 'pending',
        'next_report_after' => 3600,
    ]);
}

if ($method === 'POST' && $path === '/api/v1/reports') {
    verify_signature($body);
    $report = require_valid_report($body);

    $receipts = sys_get_temp_dir() . '/fleet-stub-receipts.txt';
    $seen = is_file($receipts) ? file($receipts, FILE_IGNORE_NEW_LINES) : [];
    if (in_array($report['report_id'], $seen, true)) {
        respond(200, ['status' => 'approved', 'duplicate' => true, 'next_report_after' => 3600]);
    }
    file_put_contents($receipts, $report['report_id'] . "\n", FILE_APPEND);

    store('report', $body);
    respond(202, ['status' => 'approved', 'next_report_after' => 3600]);
}

if ($method === 'GET' && $path === '/api/v1/installation') {
    verify_signature('');
    respond(200, [
        'installation_uuid' => '00000000-0000-4000-8000-000000000001',
        'project' => 'Stub project',
        'environment' => 'Production',
        'status' => 'approved',
    ]);
}

respond(404, ['error' => 'not_found']);
