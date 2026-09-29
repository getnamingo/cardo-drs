<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/BaseAdapter.php';
require_once __DIR__ . '/../src/Core/Http.php';
require_once __DIR__ . '/../src/Core/Types.php';
require_once __DIR__ . '/../src/adapters/OpenSRS.php';
require_once __DIR__ . '/../src/adapters/NameCom.php';
require_once __DIR__ . '/../src/adapters/Namesilo.php';
require_once __DIR__ . '/../src/adapters/Namecheap.php';
require_once __DIR__ . '/../src/adapters/Dynadot.php';

use Namingo\Cardo\DRS\Adapters\Dynadot;
use Namingo\Cardo\DRS\Adapters\Namecheap;
use Namingo\Cardo\DRS\Adapters\NameCom;
use Namingo\Cardo\DRS\Adapters\OpenSRS;
use Namingo\Cardo\DRS\Adapters\Namesilo;
use Namingo\Cardo\DRS\Core\BaseAdapter;

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

$normalizeNamesiloDns = privateMethod($namesilo, 'normalizeDnsRecord');
$namesiloRecord = $normalizeNamesiloDns->invoke($namesilo, [
    'record_id' => 'abc123',
    'type' => 'MX',
    'host' => 'example.test',
    'value' => 'mail.example.test',
    'ttl' => '7207',
    'distance' => '10',
]);

assertTrue(
    ($namesiloRecord['record_id'] ?? null) === 'abc123',
    'NameSilo DNS normalization must preserve record_id for later deletion'
);

$namecheap = new Namecheap([
    'api_user' => 'test-user',
    'api_key' => 'test-key',
    'client_ip' => '192.0.2.1',
]);

assertTrue(
    $namecheap instanceof BaseAdapter && $namecheap->brand() === 'namecheap',
    'Namecheap must instantiate as a complete BaseAdapter implementation'
);

foreach (['transferDomain', 'getDomain', 'getDNS', 'setDNS', 'addDNS', 'delDNS', 'raw'] as $method) {
    assertTrue(
        method_exists($namecheap, $method),
        "Namecheap must implement {$method}"
    );
}

$buildNamecheapRegistration = privateMethod($namecheap, 'buildRegistrationParams');
$namecheapRegistration = $buildNamecheapRegistration->invoke($namecheap, 'example.test', [
    'years' => 2,
    'registrant' => [
        'first_name' => 'Jane',
        'last_name' => 'Registrant',
        'address' => '1 Example Street',
        'city' => 'Sofia',
        'state' => 'Sofia',
        'zip' => '1000',
        'country' => 'BG',
        'phone' => '+359.2.1234567',
        'email' => 'registrant@example.test',
    ],
    'contacts' => [
        'admin' => [
            'email' => 'admin@example.test',
        ],
    ],
]);

foreach (['Registrant', 'Admin', 'Tech', 'AuxBilling'] as $prefix) {
    foreach (['FirstName', 'LastName', 'Address1', 'City', 'StateProvince', 'PostalCode', 'Country', 'Phone', 'EmailAddress'] as $field) {
        assertTrue(
            isset($namecheapRegistration[$prefix . $field]) && $namecheapRegistration[$prefix . $field] !== '',
            "Namecheap registration must supply required {$prefix}{$field}"
        );
    }
}

assertTrue(
    $namecheapRegistration['AdminFirstName'] === 'Jane'
        && $namecheapRegistration['AdminEmailAddress'] === 'admin@example.test'
        && $namecheapRegistration['TechEmailAddress'] === 'registrant@example.test'
        && $namecheapRegistration['AuxBillingEmailAddress'] === 'registrant@example.test',
    'Namecheap registration contacts must inherit registrant data while allowing dedicated overrides'
);

$namecheapFailureXml = simplexml_load_string(
    '<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">'
    . '<CommandResponse Type="namecheap.domains.dns.setHosts">'
    . '<DomainDNSSetHostsResult Domain="example.test" IsSuccess="false" />'
    . '</CommandResponse></ApiResponse>'
);
assertTrue($namecheapFailureXml !== false, 'Namecheap failure fixture must parse');

$namecheapResult = privateMethod($namecheap, 'result');
$namecheapFailure = $namecheapResult->invoke($namecheap, 200, $namecheapFailureXml);
assertTrue(
    $namecheapFailure['ok'] === false && $namecheapFailure['err'] !== '',
    'Namecheap result must report command-level IsSuccess=false as failure'
);

$namecheapSuccessXml = simplexml_load_string(
    '<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">'
    . '<CommandResponse Type="namecheap.domains.create">'
    . '<DomainCreateResult Domain="example.test" Registered="true" />'
    . '</CommandResponse></ApiResponse>'
);
assertTrue($namecheapSuccessXml !== false, 'Namecheap success fixture must parse');

$namecheapSuccess = $namecheapResult->invoke($namecheap, 200, $namecheapSuccessXml);
assertTrue(
    $namecheapSuccess['ok'] === true && $namecheapSuccess['err'] === '',
    'Namecheap result must preserve successful command-level results'
);

$dynadot = new Dynadot(['api_key' => 'test-key']);
$extractDynadotDns = privateMethod($dynadot, 'extractDnsRecords');
$dynadotRecords = $extractDynadotDns->invoke($dynadot, [
    'GetDnsResponse' => [
        'ResponseCode' => 0,
        'Status' => 'success',
        'GetDns' => [
            'NameServerSettings' => [
                'Type' => 'Dynadot DNS',
                'TTL' => '600',
                'MainDomains' => [
                    'MainDomainRecord' => [
                        ['RecordType' => 'A', 'Value' => '192.0.2.10'],
                        ['RecordType' => 'MX', 'Value' => 'mail.example.test', 'Value2' => '10'],
                    ],
                ],
                'SubDomains' => [
                    'SubDomainRecord' => [
                        'Subhost' => 'www',
                        'RecordType' => 'CNAME',
                        'Value' => 'example.test',
                    ],
                ],
            ],
        ],
    ],
]);

assertTrue(
    count($dynadotRecords) === 3,
    'Dynadot DNS parser must preserve main and subdomain records'
);
assertTrue(
    $dynadotRecords[1]['type'] === 'MX' && $dynadotRecords[1]['prio'] === 10,
    'Dynadot DNS parser must preserve MX priority'
);
assertTrue(
    $dynadotRecords[2]['host'] === 'www',
    'Dynadot DNS parser must preserve subdomain hostnames'
);

$buildDynadotDns = privateMethod($dynadot, 'buildDnsParams');
$dynadotParams = $buildDynadotDns->invoke($dynadot, 'example.test', [
    ['type' => 'A', 'host' => '@', 'value' => '192.0.2.10', 'ttl' => 600, 'prio' => null],
    ['type' => 'MX', 'host' => '@', 'value' => 'mail.example.test', 'ttl' => 600, 'prio' => 10],
    ['type' => 'CNAME', 'host' => 'www', 'value' => 'example.test', 'ttl' => 600, 'prio' => null],
]);

assertTrue(
    ($dynadotParams['main_record_type0'] ?? null) === 'a'
        && ($dynadotParams['main_record_type1'] ?? null) === 'mx'
        && ($dynadotParams['main_recordx1'] ?? null) === 10
        && ($dynadotParams['subdomain0'] ?? null) === 'www',
    'Dynadot delete rewrite must use set_dns2-compatible parameters'
);

$dynadotMatches = privateMethod($dynadot, 'dnsMatches');
assertTrue(
    $dynadotMatches->invoke($dynadot, $dynadotRecords[1], [
        'type' => 'MX',
        'host' => '@',
        'value' => 'mail.example.test',
        'priority' => 10,
    ]) === true,
    'Dynadot DNS delete matcher must support the unified selector shape'
);

assertTrue(
    $dynadotMatches->invoke($dynadot, $dynadotRecords[1], ['record_id' => '123']) === false
        && $dynadotMatches->invoke($dynadot, $dynadotRecords[1], ['typo' => 'MX']) === false
        && $dynadotMatches->invoke($dynadot, $dynadotRecords[1], ['prio' => null]) === false,
    'Dynadot DNS matcher must reject selectors with no usable supported fields'
);

$unsupportedDynadotDelete = $dynadot->delDNS('example.test', ['record_id' => '123']);
assertTrue(
    $unsupportedDynadotDelete['ok'] === false
        && str_contains($unsupportedDynadotDelete['err'] ?? '', 'must include'),
    'Dynadot delDNS must reject unsupported selectors before touching the DNS zone'
);

echo "Adapter regression tests passed.\n";
