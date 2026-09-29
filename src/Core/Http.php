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
        $options['allow_redirects'] ??= false;
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
