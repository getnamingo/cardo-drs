<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Namingo\Cardo\DRS\Adapters\OpusDNS;
use Namingo\Cardo\DRS\Core\Http;
use Namingo\Cardo\DRS\RegistrarAPI;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fails(callable $call, string $message): void
{
    try {
        $call();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException($message);
}

$history = [];
function mock(array $responses): void
{
    global $history;
    $history = [];
    $stack = HandlerStack::create(new MockHandler(array_map(
        static fn ($r) => $r instanceof Response || $r instanceof \Throwable ? $r : new Response(200, [], json_encode($r)),
        $responses
    )));
    $stack->push(Middleware::history($history));
    (new ReflectionProperty(Http::class, 'client'))->setValue(null, new Client(['handler' => $stack]));
}

function body(int $index = 0): array
{
    global $history;
    return json_decode((string) $history[$index]['request']->getBody(), true);
}

$api = RegistrarAPI::make('opus-dns', ['api_key' => 'test-key', 'sandbox' => true]);
check($api instanceof OpusDNS && $api->brand() === 'opusdns', 'Factory alias');
check(RegistrarAPI::make('OPUSDNS', ['token' => 'test-key']) instanceof OpusDNS, 'Factory uppercase');
fails(static fn () => new OpusDNS([]), 'Missing credentials must fail');
fails(static fn () => new OpusDNS(['api_key' => 'x', 'base' => 'http://example.test']), 'HTTPS required');

mock([['results' => [
    ['domain' => 'free.test', 'available' => true, 'is_premium' => true],
    ['domain' => 'used.test', 'available' => false],
    ['domain' => 'invalid', 'available' => false, 'error' => ['type' => 'validation_error', 'message' => 'Invalid']],
    ['domain' => 'error.test', 'available' => false, 'error' => ['type' => 'check_error', 'message' => 'Registry down']],
]]]);
$result = $api->checkAvailability([' FREE.test ', 'free.test', 'used.test', 'invalid', 'error.test']);
check(!$result['ok'] && $result['available'] === ['free.test'] && $result['unavailable'] === ['used.test']
    && $result['invalid'] === ['invalid'], 'Availability distinguishes registry failures from unavailable');
check((string) $history[0]['request']->getUri() === 'https://sandbox.opusdns.com/v1/domains/check?domains=free.test&domains=used.test&domains=invalid&domains=error.test', 'Repeated query keys');
check($history[0]['request']->getHeaderLine('X-Api-Key') === 'test-key', 'Authentication header');
mock([['results' => []]]);
check(!$api->checkAvailability(['missing.test'])['ok'], 'Missing availability rows fail');
mock([]);
check($api->checkAvailability([])['ok'] && $history === [], 'Empty availability does not call API');

$registrant = ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.test',
    'phone' => '+1.5555555555', 'address' => '1 Main Street', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'de'];
mock([['contact_id' => 'contact_test'], ['name' => 'new.test']]);
check($api->registerDomain('new.test', ['contacts' => ['registrant' => $registrant, 'tech' => $registrant],
    'years' => 2, 'auto_renew' => true, 'create_zone' => true, 'privacy' => true,
    'nameservers' => ['ns1.example.test'], 'expected_price' => '12.34'])['ok'], 'Registration success');
check(count($history) === 2, 'Identical contacts are created once per operation');
check(body()['street'] === '1 Main Street' && body()['postal_code'] === '10115'
    && body()['country'] === 'DE' && body()['disclose'] === false, 'Contact aliases and disclosure');
check(body(1)['contacts']['registrant'] === [['contact_id' => 'contact_test']]
    && body(1)['contacts']['tech'] === [['contact_id' => 'contact_test']]
    && body(1)['period'] === ['unit' => 'y', 'value' => 2]
    && body(1)['renewal_mode'] === 'renew' && body(1)['expected_price'] === '12.34'
    && body(1)['nameservers'] === [['hostname' => 'ns1.example.test']], 'Registration wire payload');
mock([['name' => 'handles.test']]);
$api->registerDomain('handles.test', ['contacts' => ['registrant' => [
    ['contact_id' => 'existing', 'attributes' => ['flag' => 'yes']],
]]]);
check(count($history) === 1 && body()['contacts']['registrant'][0]['attributes'] === ['flag' => 'yes'], 'Existing handles and inline attributes');
mock([new Response(422, [], '{"detail":"Contact rejected"}')]);
check(!$api->registerDomain('bad.test', ['registrant' => $registrant])['ok'] && count($history) === 1, 'Contact failure stops registration');
mock([]);
fails(static fn () => $api->registerDomain('bad.test', ['contacts' => ['registrant' => $registrant, 'tech' => []]]), 'Malformed contacts fail');
check($history === [], 'Validate contacts before creating objects');

mock([['name' => 'renew.test', 'expires_on' => '2027-01-02T05:06:07+02:00'], ['name' => 'renew.test']]);
check($api->renewDomain('renew.test', 2)['ok'], 'Renewal');
check(body(1)['current_expiry_date'] === '2027-01-02T03:06:07Z'
    && body(1)['period']['value'] === 2, 'Renewal preserves expiry instant');
mock([new Response(404, [], '{"detail":"Not found"}')]);
check(!$api->renewDomain('missing.test')['ok'] && count($history) === 1, 'Renewal stops when lookup fails');
mock([['name' => 'transfer.test']]);
$api->transferDomain('transfer.test', ['auth_code' => 'EPP', 'contacts' => ['registrant' => 'existing']]);
check(body()['auth_code'] === 'EPP' && !isset(body()['period']) && body()['contacts']['registrant'] === [['contact_id' => 'existing']], 'Transfer preserves registry default period');
check($history[0]['request']->getUri()->getPath() === '/v1/domains/transfer', 'Transfer endpoint');
mock([['name' => 'info.test', 'expires_on' => '2027-01-01T00:00:00Z', 'renewal_mode' => 'renew',
    'transfer_lock' => true, 'nameservers' => [['hostname' => 'ns1.example.test']]]]);
$info = $api->getDomain('info.test');
check($info['auto_renew'] && $info['locked'] && $info['nameservers'] === ['ns1.example.test'], 'Domain normalization');
mock([['name' => 'info.test']]);
$api->setNameServers('info.test', ['ns1.example.test']);
check($history[0]['request']->getMethod() === 'PATCH' && body()['nameservers'] === [['hostname' => 'ns1.example.test']], 'Nameserver update');

$zone = ['name' => 'example.test.', 'rrsets' => [
    ['name' => 'example.test.', 'type' => 'MX', 'ttl' => 600, 'records' => [['rdata' => '10 mail.example.test.'], ['rdata' => '20 backup.example.test.']]],
    ['name' => '_sip._tcp.example.test.', 'type' => 'SRV', 'ttl' => 600, 'records' => [['rdata' => '5 10 5060 sip.example.test.']]],
    ['name' => 'example.test.', 'type' => 'NS', 'ttl' => 3600, 'protected' => true, 'records' => [['rdata' => 'ns1.opusdns.com.']]],
]];
mock([$zone]);
$dns = $api->getDNS('example.test');
check(count($dns['records']) === 4 && $dns['records'][0]['host'] === '@' && $dns['records'][0]['prio'] === 10
    && $dns['records'][0]['value'] === 'mail.example.test.' && $dns['records'][2]['value'] === '10 5060 sip.example.test.', 'DNS RRset normalization');
mock([new Response(204)]);
$api->addDNS('example.test', ['type' => 'mx', 'host' => '@', 'value' => 'mail.example.test.', 'priority' => 10, 'ttl' => 600]);
check(body()['ops'][0] === ['op' => 'upsert', 'record' => ['name' => 'example.test.', 'type' => 'MX', 'ttl' => 600, 'rdata' => '10 mail.example.test.']], 'Add DNS wire format');
mock([new Response(204)]);
$result = $api->setDNS('example.test', [
    ['type' => 'A', 'host' => 'www', 'value' => '192.0.2.1', 'ttl' => 600],
    ['type' => 'A', 'host' => 'www.example.test.', 'value' => '192.0.2.2', 'ttl' => 600],
]);
check($result['ok'] && $history[0]['request']->getMethod() === 'PUT' && count(body()['rrsets']) === 1
    && count(body()['rrsets'][0]['records']) === 2, 'Set DNS groups RRsets in one request');
mock([]);
fails(static fn () => $api->setDNS('example.test', [
    ['type' => 'A', 'host' => '@', 'value' => '192.0.2.1', 'ttl' => 600],
    ['type' => 'A', 'host' => '@', 'value' => '192.0.2.2', 'ttl' => 300],
]), 'Mixed RRset TTLs must fail');
check($history === [], 'No mutation on invalid DNS input');
mock([$zone, new Response(204)]);
$deleted = $api->delDNS('example.test', ['type' => 'mx', 'host' => 'example.test.', 'priority' => 10]);
check($deleted['ok'] && $deleted['deleted'] === 1 && body(1)['ops'][0]['record']['rdata'] === '10 mail.example.test.', 'Delete only selected priority');
mock([$zone]);
check(!$api->delDNS('example.test', ['type' => 'NS'])['ok'] && count($history) === 1, 'Protected records cannot be deleted');
mock([]);
fails(static fn () => $api->delDNS('example.test', []), 'Empty selector must fail');
fails(static fn () => $api->delDNS('example.test', ['record_id' => 'x']), 'No record IDs');
fails(static fn () => $api->addDNS('example.test', ['type' => 'A', 'host' => 'outside.test.', 'value' => '192.0.2.1']), 'Out of zone host must fail');
mock([['name' => 'example.test']]);
check(!$api->getDNS('example.test')['ok'], 'Missing RRsets cannot be treated as empty zone');

mock([new Response(422, [], '{"detail":[{"msg":"Bad input"}]}'), new Response(200, [], 'not-json'), new Response(302), new Response(204)]);
check(!$api->raw('/v1/domains')['ok'], 'Structured API error');
check(!$api->raw('/v1/domains')['ok'], 'Malformed JSON fails');
check(!$api->raw('/v1/domains')['ok'], 'Redirect is not success');
check($api->raw('/v1/dns/example.test/records', ['method' => 'PATCH', 'body' => ['ops' => []]])['ok'], 'Empty 204 is success');
fails(static fn () => $api->raw('https://other.test'), 'Raw must stay on configured endpoint');
mock([$zone, new Response(422, [], '{"detail":"Rejected"}')]);
$result = $api->delDNS('example.test', ['type' => 'MX', 'priority' => 10]);
check(!$result['ok'] && $result['deleted'] === 0, 'Failed deletion does not claim success');
mock([new \GuzzleHttp\Exception\ConnectException('Connection failed', new \GuzzleHttp\Psr7\Request('GET', 'https://sandbox.opusdns.com/v1/domains'))]);
check(!$api->getDomain('example.test')['ok'], 'Transport failure is normalized');
$many = array_map(static fn ($n) => "domain{$n}.test", range(1, 51));
mock([
    ['results' => array_map(static fn ($d) => ['domain' => $d, 'available' => true], array_slice($many, 0, 50))],
    ['results' => [['domain' => $many[50], 'available' => false]]],
]);
$result = $api->checkAvailability($many);
check($result['ok'] && count($result['available']) === 50 && count($history) === 2, 'Availability batches large requests');
mock([['contact_id' => 'created-first'], new Response(422, [], '{"detail":"Contact rejected"}')]);
$result = $api->registerDomain('new.test', ['contacts' => [
    'registrant' => $registrant, 'tech' => array_replace($registrant, ['email' => 'other@example.test']),
]]);
check(!$result['ok'] && $result['created_contact_ids'] === ['created-first'] && count($history) === 2, 'Partial contact creation returns IDs and stops registration');
(new ReflectionProperty(Http::class, 'client'))->setValue(null, null);
echo "OpusDNS mocked HTTP regression tests passed.\n";
