<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/BaseAdapter.php';
require_once __DIR__ . '/../src/Core/Http.php';
require_once __DIR__ . '/../src/adapters/OpenSRS.php';
require_once __DIR__ . '/../src/adapters/NameCom.php';
require_once __DIR__ . '/../src/adapters/Namesilo.php';

use Namingo\Cardo\DRS\Adapters\NameCom;
use Namingo\Cardo\DRS\Adapters\OpenSRS;
use Namingo\Cardo\DRS\Adapters\Namesilo;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function privateMethod(object $object, string $name): ReflectionMethod
{
    $method = new ReflectionMethod($object, $name);
    $method->setAccessible(true);
    return $method;
}

$opensrs = new OpenSRS([
    'api_key' => 'test-key',
    'username' => 'test-user',
    'password' => 'test-password',
]);

$arrayNode = privateMethod($opensrs, 'arrayNode');
$xml = $arrayNode->invoke($opensrs, [
    ['name' => 'ns1.example.test', 'sortorder' => 0],
    ['name' => 'ns2.example.test', 'sortorder' => 1],
]);

assertTrue(
    str_contains($xml, '<dt_array><dt_assoc>'),
    'OpenSRS compound array entries must be direct dt_assoc children'
);
assertTrue(
    !str_contains($xml, '<item key="0"><dt_assoc>'),
    'OpenSRS compound array entries must not be wrapped in item elements'
);

$parse = privateMethod($opensrs, 'parse');
$parsed = $parse->invoke($opensrs, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<OPS_envelope>
  <header><version>0.9</version></header>
  <body>
    <data_block>
      <dt_assoc>
        <item key="response_code">200</item>
        <item key="attributes">
          <dt_assoc>
            <item key="nameserver_list">
              <dt_array>
                <dt_assoc>
                  <item key="name">ns1.example.test</item>
                  <item key="sortorder">0</item>
                </dt_assoc>
                <dt_assoc>
                  <item key="name">ns2.example.test</item>
                  <item key="sortorder">1</item>
                </dt_assoc>
              </dt_array>
            </item>
          </dt_assoc>
        </item>
      </dt_assoc>
    </data_block>
  </body>
</OPS_envelope>
XML);

assertTrue(
    ($parsed['attributes']['nameserver_list'][0]['name'] ?? null) === 'ns1.example.test',
    'OpenSRS parser must read direct dt_assoc children in dt_array'
);
assertTrue(
    ($parsed['attributes']['nameserver_list'][1]['name'] ?? null) === 'ns2.example.test',
    'OpenSRS parser must preserve all compound array entries'
);

$namecom = new NameCom([
    'username' => 'test-user',
    'token' => 'test-token',
]);

$matches = privateMethod($namecom, 'dnsMatches');
$mx = [
    'type' => 'MX',
    'host' => '@',
    'value' => 'mail.example.test',
    'ttl' => 300,
    'prio' => 10,
];

assertTrue(
    $matches->invoke($namecom, $mx, [
        'type' => 'MX',
        'host' => '@',
        'value' => 'mail.example.test',
        'priority' => 10,
    ]) === true,
    'Name.com DNS matcher must accept matching priority aliases'
);

assertTrue(
    $matches->invoke($namecom, $mx, [
        'type' => 'MX',
        'host' => '@',
        'value' => 'mail.example.test',
        'prio' => 20,
    ]) === false,
    'Name.com DNS matcher must not delete records with a different priority'
);

$namesilo = new Namesilo(['api_key' => 'super-secret-key']);
$redactQuery = privateMethod($namesilo, 'redactQuery');
$redacted = $redactQuery->invoke($namesilo, [
    'key' => 'super-secret-key',
    'auth' => 'transfer-secret',
    'auth_code' => 'another-secret',
    'epp_code' => 'epp-secret',
    'domain' => 'example.test',
]);

assertTrue(
    $redacted['key'] === '***'
        && $redacted['auth'] === '***'
        && $redacted['auth_code'] === '***'
        && $redacted['epp_code'] === '***',
    'NameSilo endpoint metadata must redact API and transfer credentials'
);

assertTrue(
    $redacted['domain'] === 'example.test',
    'NameSilo endpoint metadata must preserve non-sensitive query parameters'
);

echo "Adapter regression tests passed.\n";
