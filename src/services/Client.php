<?php

namespace onedarnleyroad\fleetreporter\services;

use Craft;
use GuzzleHttp\Exception\RequestException;
use Throwable;

/**
 * Talks to Fleet's API. Every call returns [httpStatus|null, decodedBody|null, error|null] and never throws.
 */
class Client
{
    /** @var string */
    private $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function register(string $orgKey, string $body): array
    {
        return $this->send('POST', '/api/v1/register', $body, ['Authorization' => 'Bearer ' . $orgKey]);
    }

    public function report(string $keyId, string $secret, string $body): array
    {
        return $this->send('POST', '/api/v1/reports', $body, self::signatureHeaders($keyId, $secret, $body));
    }

    public function installation(string $keyId, string $secret): array
    {
        return $this->send('GET', '/api/v1/installation', '', self::signatureHeaders($keyId, $secret, ''));
    }

    public static function signatureHeaders(string $keyId, string $secret, string $body): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        return [
            'X-Fleet-Key' => $keyId,
            'X-Fleet-Timestamp' => $timestamp,
            'X-Fleet-Nonce' => $nonce,
            'X-Fleet-Signature' => hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . hash('sha256', $body), $secret),
        ];
    }

    private function send(string $method, string $path, string $body, array $headers): array
    {
        $options = [
            'headers' => $headers + ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'connect_timeout' => 3,
            'timeout' => 8,
            'http_errors' => false,
        ];
        if ($body !== '') {
            $options['body'] = $body;
        }

        try {
            $response = Craft::createGuzzleClient()->request($method, $this->baseUrl . $path, $options);
        } catch (RequestException $e) {
            return [null, null, 'connection: ' . $e->getMessage()];
        } catch (Throwable $e) {
            return [null, null, get_class($e) . ': ' . $e->getMessage()];
        }

        $status = $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        $error = $status >= 400 ? ('HTTP ' . $status . (isset($decoded['error']) ? ' ' . $decoded['error'] : '')) : null;

        return [$status, is_array($decoded) ? $decoded : null, $error];
    }
}
