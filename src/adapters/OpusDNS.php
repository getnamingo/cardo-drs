<?php

namespace Namingo\Cardo\DRS\Adapters;

use Namingo\Cardo\DRS\Core\BaseAdapter;
use Namingo\Cardo\DRS\Core\Http;

/** OpusDNS v1 API, following OpusDNS/opusdns-php-client's OpenAPI contract. */
final class OpusDNS extends BaseAdapter
{
    protected string $brand = 'opusdns';
    private string $apiKey;
    private string $base;

    public function __construct(array $creds)
    {
        parent::__construct($creds);
        $this->apiKey = trim((string) ($creds['api_key'] ?? $creds['token'] ?? ''));
        $this->base = rtrim((string) ($creds['base'] ?? $creds['endpoint']
            ?? (!empty($creds['sandbox']) ? 'https://sandbox.opusdns.com' : 'https://api.opusdns.com')), '/');
        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('OpusDNS requires an api_key');
        }
        if (filter_var($this->base, FILTER_VALIDATE_URL) === false
            || parse_url($this->base, PHP_URL_SCHEME) !== 'https') {
            throw new \InvalidArgumentException('OpusDNS requires an HTTPS endpoint');
        }
    }

    public function checkAvailability(array $domains): array
    {
        $domains = array_values(array_unique(array_map(static fn ($d) => strtolower(trim((string) $d)), $domains)));
        $result = ['ok' => true, 'available' => [], 'unavailable' => [], 'invalid' => [], 'raw' => [], 'err' => ''];
        foreach (array_chunk($domains, 50) as $chunk) {
            // OpenAPI arrays use repeated keys, not PHP's domains[0] encoding.
            $query = implode('&', array_map(static fn ($d) => 'domains=' . rawurlencode($d), $chunk));
            $reply = $this->request('GET', '/v1/domains/check?' . $query);
            $result['raw'][] = $reply['raw'];
            if (!$reply['ok']) {
                $result['ok'] = false;
                $result['err'] = $reply['err'];
                continue;
            }
            $seen = [];
            foreach ($reply['raw']['results'] ?? [] as $row) {
                $name = strtolower((string) ($row['domain'] ?? ''));
                if (!in_array($name, $chunk, true)) {
                    continue;
                }
                $seen[$name] = true;
                if (!empty($row['error'])) {
                    if (($row['error']['type'] ?? '') === 'validation_error') {
                        $result['invalid'][] = $name;
                    } else {
                        $result['ok'] = false;
                        $result['err'] = (string) ($row['error']['message'] ?? 'Registry check failed');
                    }
                } elseif (isset($row['available']) && is_bool($row['available'])) {
                    $result[$row['available'] ? 'available' : 'unavailable'][] = $name;
                } else {
                    $result['ok'] = false;
                    $result['err'] = 'Malformed OpusDNS availability response';
                }
            }
            if (count($seen) !== count($chunk)) {
                $result['ok'] = false;
                $result['err'] = 'Incomplete OpusDNS availability response';
            }
        }
        return $result;
    }

    public function registerDomain(string $domain, array $opts): array
    {
        $body = $this->domainOptions($domain, $opts);
        $body['period'] = $this->period((int) ($opts['years'] ?? 1));
        $contacts = $this->contacts($opts, true);
        if (!$contacts['ok']) {
            return $contacts;
        }
        $body['contacts'] = $contacts['contacts'];
        return $this->request('POST', '/v1/domains', $body) + ['contacts' => $contacts['contacts']];
    }

    public function renewDomain(string $domain, int $years = 1, array $opts = []): array
    {
        $body = ['period' => $this->period($years)];
        $expiry = $opts['current_expiry_date'] ?? null;
        if ($expiry === null) {
            $current = $this->getDomain($domain);
            if (!$current['ok']) {
                return $current;
            }
            $expiry = $current['expires_at'];
        }
        if (!$expiry) {
            return ['ok' => false, 'raw' => [], 'err' => 'OpusDNS renewal requires the current expiry date'];
        }
        // Match the client serializer: retain the expiry instant in UTC.
        $body['current_expiry_date'] = (new \DateTimeImmutable((string) $expiry, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        if (isset($opts['expected_price'])) {
            $body['expected_price'] = (string) $opts['expected_price'];
        }
        return $this->request('POST', $this->domainPath($domain) . '/renew', $body);
    }

    public function transferDomain(string $domain, array $opts): array
    {
        $body = $this->domainOptions($domain, $opts);
        if (isset($opts['years'])) {
            $body['period'] = $this->period((int) $opts['years']);
        }
        if (isset($opts['contacts']) || isset($opts['registrant'])) {
            $contacts = $this->contacts($opts, false);
            if (!$contacts['ok']) {
                return $contacts;
            }
            $body['contacts'] = $contacts['contacts'];
        }
        return $this->request('POST', '/v1/domains/transfer', $body)
            + (isset($body['contacts']) ? ['contacts' => $body['contacts']] : []);
    }

    public function getDomain(string $domain): array
    {
        $result = $this->request('GET', $this->domainPath($domain));
        if ($result['ok']) {
            $data = $result['raw'];
            $result += [
                'domain' => $data['name'] ?? $domain,
                'created_at' => $data['registered_on'] ?? $data['created_on'] ?? null,
                'expires_at' => $data['expires_on'] ?? null,
                'auto_renew' => isset($data['renewal_mode']) ? $data['renewal_mode'] === 'renew' : null,
                'locked' => $data['transfer_lock'] ?? null,
                'nameservers' => array_column($data['nameservers'] ?? [], 'hostname'),
            ];
        }
        return $result;
    }

    public function setNameServers(string $domain, array $nameservers): array
    {
        return $this->request('PATCH', $this->domainPath($domain), [
            'nameservers' => array_map(static fn ($ns) => ['hostname' => (string) $ns], array_values($nameservers)),
        ]);
    }

    public function getDNS(string $domain): array
    {
        $result = $this->request('GET', $this->zonePath($domain));
        $result['records'] = [];
        if (!$result['ok']) {
            return $result;
        }
        if (!isset($result['raw']['rrsets']) || !is_array($result['raw']['rrsets'])) {
            return array_replace($result, ['ok' => false, 'err' => 'OpusDNS zone response is missing rrsets']);
        }
        foreach ($result['raw']['rrsets'] as $rrset) {
            foreach ($rrset['records'] ?? [] as $record) {
                $type = strtoupper((string) $rrset['type']);
                $value = (string) $record['rdata'];
                $priority = null;
                if (in_array($type, ['MX', 'SRV'], true) && preg_match('/^(\d+)\s+(.+)$/s', $value, $match)) {
                    $priority = (int) $match[1];
                    $value = $match[2];
                }
                $name = strtolower(rtrim((string) $rrset['name'], '.'));
                $zone = strtolower(rtrim($domain, '.'));
                $host = $name === $zone ? '@' : (str_ends_with($name, '.' . $zone) ? substr($name, 0, -strlen($zone) - 1) : $name . '.');
                $result['records'][] = [
                    'type' => $type, 'host' => $host, 'value' => $value,
                    'ttl' => (int) $rrset['ttl'], 'prio' => $priority,
                    'protected' => !empty($rrset['protected']) || !empty($record['protected']),
                ];
            }
        }
        return $result;
    }

    public function setDNS(string $domain, array $records): array
    {
        // Validate and group before sending anything. One RRset has one TTL.
        $rrsets = [];
        foreach ($records as $record) {
            $item = $this->dnsRecord($domain, $record);
            $key = $item['name'] . "\0" . $item['type'];
            if (isset($rrsets[$key]) && $rrsets[$key]['ttl'] !== $item['ttl']) {
                throw new \InvalidArgumentException('OpusDNS records in one RRset must have the same TTL');
            }
            $rrsets[$key] ??= ['name' => $item['name'], 'type' => $item['type'], 'ttl' => $item['ttl'], 'records' => []];
            $rrsets[$key]['records'][] = ['rdata' => $item['rdata']];
        }
        return $this->request('PUT', $this->zonePath($domain) . '/rrsets', ['rrsets' => array_values($rrsets)]);
    }

    public function addDNS(string $domain, array $record): array
    {
        return $this->request('PATCH', $this->zonePath($domain) . '/records', [
            'ops' => [['op' => 'upsert', 'record' => $this->dnsRecord($domain, $record)]],
        ]);
    }

    public function delDNS(string $domain, array $selector): array
    {
        if (!array_intersect(['type', 'host', 'value', 'prio', 'priority'], array_keys($selector))) {
            throw new \InvalidArgumentException('OpusDNS deletion requires a DNS selector; record IDs are not supported');
        }
        $current = $this->getDNS($domain);
        if (!$current['ok']) {
            return $current;
        }
        $ops = [];
        foreach ($current['records'] as $record) {
            if (!$this->dnsMatches($domain, $record, $selector)) {
                continue;
            }
            if ($record['protected']) {
                return ['ok' => false, 'raw' => [], 'err' => 'Cannot delete a protected OpusDNS record'];
            }
            $ops[] = ['op' => 'remove', 'record' => $this->dnsRecord($domain, $record)];
        }
        if ($ops === []) {
            return ['ok' => false, 'deleted' => 0, 'raw' => [], 'err' => 'No matching OpusDNS DNS record'];
        }
        $result = $this->request('PATCH', $this->zonePath($domain) . '/records', ['ops' => $ops]);
        return $result + ['deleted' => $result['ok'] ? count($ops) : 0];
    }

    /** Path relative to the API root, including /v1; params: method, body, query. */
    public function raw(string $op, array $params = []): array
    {
        if (!str_starts_with($op, '/v1/') || str_contains($op, '#')) {
            throw new \InvalidArgumentException('OpusDNS raw path must start with /v1/');
        }
        $query = [];
        foreach ($params['query'] ?? [] as $key => $values) {
            foreach (is_array($values) ? $values : [$values] as $value) {
                $query[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
            }
        }
        if ($query !== []) {
            $op .= (str_contains($op, '?') ? '&' : '?') . implode('&', $query);
        }
        return $this->request(strtoupper((string) ($params['method'] ?? 'GET')), $op, $params['body'] ?? null);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $options = ['headers' => ['X-Api-Key' => $this->apiKey, 'Accept' => 'application/json']];
        if ($body !== null) {
            $options['json'] = $body;
        }
        [$code, $response, $error] = Http::request($method, $this->base . $path, $options);
        $data = $response === '' ? [] : json_decode($response, true);
        $valid = is_array($data);
        $data = $valid ? $data : ['_raw' => $response];
        $ok = $code >= 200 && $code < 300 && $error === '' && $valid;
        if (!$ok && $error === '') {
            $detail = $data['detail'] ?? $data['message'] ?? null;
            $error = !$valid ? 'Invalid OpusDNS JSON response' : (is_string($detail) ? $detail : json_encode($detail ?? ['http' => $code]));
        }
        return ['ok' => $ok, 'raw' => $data, 'http' => $code, 'err' => $error];
    }

    private function domainOptions(string $domain, array $opts): array
    {
        $body = ['name' => $domain, 'renewal_mode' => !empty($opts['auto_renew']) ? 'renew' : 'expire'];
        foreach (['attributes', 'claims_notice_acceptance_hash', 'create_zone'] as $key) {
            if (array_key_exists($key, $opts)) {
                $body[$key] = $key === 'attributes' && $opts[$key] === [] ? new \stdClass() : $opts[$key];
            }
        }
        if (isset($opts['auth_code']) || isset($opts['authCode'])) {
            $body['auth_code'] = (string) ($opts['auth_code'] ?? $opts['authCode']);
        }
        if (isset($opts['expected_price'])) {
            $body['expected_price'] = (string) $opts['expected_price'];
        }
        if (isset($opts['nameservers'])) {
            $body['nameservers'] = array_map(static fn ($ns) => is_array($ns) ? $ns : ['hostname' => (string) $ns], array_values($opts['nameservers']));
        }
        return $body;
    }

    private function contacts(array $opts, bool $required): array
    {
        $contacts = $opts['contacts'] ?? (isset($opts['registrant']) ? ['registrant' => $opts['registrant']] : []);
        if ($required && empty($contacts['registrant'])) {
            throw new \InvalidArgumentException('OpusDNS registration requires a registrant contact');
        }
        // Validate every new contact before creating any provider objects.
        foreach ($contacts as $role => $entries) {
            if (!in_array($role, ['registrant', 'admin', 'tech', 'billing'], true)) {
                throw new \InvalidArgumentException('Unknown OpusDNS contact role: ' . $role);
            }
            if ($entries === []) {
                throw new \InvalidArgumentException('OpusDNS contact role requires at least one contact');
            }
            foreach (is_array($entries) && array_is_list($entries) ? $entries : [$entries] as $entry) {
                if (is_array($entry) && empty($entry['contact_id'])) {
                    $this->contactData($entry, $opts);
                } elseif ((!is_string($entry) || trim($entry) === '') && (!is_array($entry) || empty($entry['contact_id']))) {
                    throw new \InvalidArgumentException('Invalid OpusDNS contact');
                }
            }
        }
        $normalized = [];
        $created = [];
        foreach ($contacts as $role => $entries) {
            if (!in_array($role, ['registrant', 'admin', 'tech', 'billing'], true)) {
                throw new \InvalidArgumentException('Unknown OpusDNS contact role: ' . $role);
            }
            $entries = is_array($entries) && array_is_list($entries) ? $entries : [$entries];
            foreach ($entries as $entry) {
                if (is_string($entry)) {
                    $entry = ['contact_id' => $entry];
                }
                if (!is_array($entry)) {
                    throw new \InvalidArgumentException('Invalid OpusDNS contact');
                }
                if (!empty($entry['contact_id'])) {
                    $handle = ['contact_id' => (string) $entry['contact_id']];
                } else {
                    $data = $this->contactData($entry, $opts);
                    $key = json_encode($data);
                    if (!isset($created[$key])) {
                        $reply = $this->request('POST', '/v1/contacts', $data);
                        if (!$reply['ok']) {
                            return $reply + ['contacts' => $normalized, 'created_contact_ids' => array_values($created)];
                        }
                        if (empty($reply['raw']['contact_id'])) {
                            return [
                                'ok' => false,
                                'raw' => $reply['raw'],
                                'err' => 'OpusDNS contact response is missing contact_id',
                                'created_contact_ids' => array_values($created),
                            ];
                        }
                        $created[$key] = (string) $reply['raw']['contact_id'];
                    }
                    $handle = ['contact_id' => $created[$key]];
                }
                if (isset($entry['attributes'])) {
                    $handle['attributes'] = $entry['attributes'] === [] ? new \stdClass() : $entry['attributes'];
                }
                $normalized[$role][] = $handle;
            }
        }
        return ['ok' => true, 'contacts' => $normalized];
    }

    private function contactData(array $entry, array $opts): array
    {
        $aliases = [
            'first_name' => ['first_name', 'firstName', 'firstname'],
            'last_name' => ['last_name', 'lastName', 'lastname'],
            'street' => ['street', 'address', 'address1'],
            'postal_code' => ['postal_code', 'postalCode', 'postalcode', 'zip'],
            'org' => ['org', 'company', 'organization', 'companyName'],
            'state' => ['state', 'province'],
        ];
        $data = [];
        foreach (['first_name', 'last_name', 'street', 'postal_code', 'city', 'country', 'email', 'phone', 'org', 'state', 'fax', 'title'] as $key) {
            foreach ($aliases[$key] ?? [$key] as $alias) {
                if (isset($entry[$alias])) {
                    $data[$key] = (string) $entry[$alias];
                    break;
                }
            }
        }
        foreach (['first_name', 'last_name', 'street', 'postal_code', 'city', 'country', 'email', 'phone'] as $key) {
            if (empty($data[$key])) {
                throw new \InvalidArgumentException('OpusDNS contact requires ' . $key);
            }
        }
        $data['country'] = strtoupper($data['country']);
        $data['disclose'] = (bool) ($entry['disclose'] ?? false);
        if (!empty($opts['privacy'])) {
            $data['disclose'] = false;
        }
        return $data;
    }

    private function period(int $years): array
    {
        if ($years < 1 || $years > 10) {
            throw new \InvalidArgumentException('OpusDNS years must be between 1 and 10');
        }
        return ['unit' => 'y', 'value' => $years];
    }

    private function domainPath(string $domain): string { return '/v1/domains/' . rawurlencode($domain); }
    private function zonePath(string $domain): string { return '/v1/dns/' . rawurlencode(rtrim($domain, '.')); }

    private function dnsName(string $domain, string $host): string
    {
        $zone = strtolower(rtrim($domain, '.'));
        $host = strtolower($host);
        if ($host === '' || $host === '@' || rtrim($host, '.') === $zone) {
            return $zone . '.';
        }
        if (str_ends_with($host, '.')) {
            $name = rtrim($host, '.');
            if (!str_ends_with($name, '.' . $zone)) {
                throw new \InvalidArgumentException('DNS host is outside the OpusDNS zone');
            }
            return $host;
        }
        return (str_ends_with($host, '.' . $zone) ? $host : $host . '.' . $zone) . '.';
    }

    private function dnsRecord(string $domain, array $record): array
    {
        $type = strtoupper((string) ($record['type'] ?? ''));
        $ttl = (int) ($record['ttl'] ?? 3600);
        if ($type === '' || $ttl < 1 || !array_key_exists('value', $record)) {
            throw new \InvalidArgumentException('OpusDNS DNS record requires type, value and a positive TTL');
        }
        $value = (string) $record['value'];
        if (in_array($type, ['MX', 'SRV'], true) && isset($record['prio'])) {
            $value = (int) $record['prio'] . ' ' . $value;
        } elseif (in_array($type, ['MX', 'SRV'], true) && isset($record['priority'])) {
            $value = (int) $record['priority'] . ' ' . $value;
        }
        return ['name' => $this->dnsName($domain, (string) ($record['host'] ?? '@')), 'type' => $type, 'ttl' => $ttl, 'rdata' => $value];
    }

    private function dnsMatches(string $domain, array $record, array $selector): bool
    {
        foreach (['type', 'host', 'value', 'prio', 'priority'] as $key) {
            if (!array_key_exists($key, $selector)) {
                continue;
            }
            $left = $record[$key === 'priority' ? 'prio' : $key] ?? null;
            $right = $selector[$key];
            if ($key === 'type') {
                $left = strtoupper((string) $left); $right = strtoupper((string) $right);
            } elseif ($key === 'host') {
                $left = $this->dnsName($domain, (string) $left); $right = $this->dnsName($domain, (string) $right);
            }
            if ((string) $left !== (string) $right) {
                return false;
            }
        }
        return true;
    }
}
