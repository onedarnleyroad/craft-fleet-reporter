<?php

declare(strict_types=1);

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

require __DIR__ . '/vendor/autoload.php';

const STUB_ORG_KEY = 'fok_stub';
const STUB_KEY_ID = 'key_stub';
const STUB_SECRET = 'stub-secret';
const STUB_MAX_SKEW = 300;

function schema_path(): string
{
    return dirname(__DIR__) . '/fixtures/report-v1.schema.json';
}

/**
 * @return array<string, string[]> JSON pointer → messages; empty when valid
 */
function validate_report(string $json): array
{
    $data = json_decode($json);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['/' => ['Body is not valid JSON: ' . json_last_error_msg()]];
    }

    $validator = new Validator();
    $validator->setMaxErrors(50);
    $validator->resolver()->registerFile(
        'urn:fleet:report:v1',
        schema_path()
    );

    $result = $validator->validate($data, 'urn:fleet:report:v1');
    if ($result->isValid()) {
        return [];
    }

    return (new ErrorFormatter())->format($result->error(), true);
}

function sign(string $timestamp, string $nonce, string $body, string $secret): string
{
    return hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . hash('sha256', $body), $secret);
}
