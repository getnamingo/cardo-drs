<?php
namespace Namingo\Cardo\DRS\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class Http
{
    private static ?Client $client = null;

    public static function request(
        string $method,
        string $url,
        array $options = [],
        int $timeout = 30
    ): array {
        $options['http_errors'] = false;
        $options['timeout'] ??= $timeout;
        $options['connect_timeout'] ??= $timeout;

        if (isset($options['headers'])) {
            $options['headers'] = self::normalizeHeaders($options['headers']);
        }

        try {
            $response = self::client()->request($method, $url, $options);

            return [
                $response->getStatusCode(),
                (string) $response->getBody(),
                '',
            ];
        } catch (GuzzleException $e) {
            return [0, '', $e->getMessage()];
        }
    }

    public static function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, ['headers' => $headers], $timeout);
    }

    public static function delete(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('DELETE', $url, ['headers' => $headers], $timeout);
    }

    public static function postJson(string $url, array $json, array $headers = [], int $timeout = 30): array
    {
        return self::request('POST', $url, ['headers' => $headers, 'json' => $json], $timeout);
    }

    public static function putJson(string $url, array $json, array $headers = [], int $timeout = 30): array
    {
        return self::request('PUT', $url, ['headers' => $headers, 'json' => $json], $timeout);
    }

    private static function client(): Client
    {
        return self::$client ??= new Client();
    }

    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_string($name)) {
                $normalized[$name] = $value;
                continue;
            }

            [$header, $headerValue] = array_pad(explode(':', (string) $value, 2), 2, '');
            $normalized[trim($header)] = trim($headerValue);
        }

        return $normalized;
    }
}
