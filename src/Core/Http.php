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

}
