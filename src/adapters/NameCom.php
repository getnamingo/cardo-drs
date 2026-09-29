<?php

namespace Namingo\Cardo\DRS\Adapters;

use Namingo\Cardo\DRS\Core\BaseAdapter;
use Namingo\Cardo\DRS\Core\Http;

final class NameCom extends BaseAdapter
{
    protected string $brand = 'namecom';

    private string $username;
    private string $token;
    private string $base;

    public function __construct(array $creds)
    {
        parent::__construct($creds);

        $this->username = (string) ($creds['username'] ?? $creds['api_user'] ?? '');
        $this->token = (string) ($creds['token'] ?? $creds['api_token'] ?? $creds['api_key'] ?? '');
        $this->base = rtrim((string) ($creds['base'] ?? $creds['endpoint'] ?? 'https://api.name.com'), '/');

        if ($this->username === '' || $this->token === '') {
            throw new \InvalidArgumentException('Name.com requires username and token credentials');
        }

        if (str_starts_with($this->base, 'http://')) {
            $this->base = 'https://' . substr($this->base, 7);
        } elseif (!str_starts_with($this->base, 'https://')) {
            $this->base = 'https://' . $this->base;
        }
    }

    public function checkAvailability(array $domains): array
    {
        $domains = array_values(array_unique(array_map(
            static fn ($domain): string => strtolower(trim((string) $domain)),
            $domains
        )));

        $available = [];
        $unavailable = [];
        $invalid = [];
        $raw = [];
        $ok = true;

        foreach (array_chunk($domains, 50) as $chunk) {
            [$code, $data, $err] = $this->request('POST', '/core/v1/domains:checkAvailability', [
                'domainNames' => $chunk,
            ]);
            $raw[] = $data;

            if ($err !== '' || $code >= 400) {
                $ok = false;
                $message = strtolower((string) ($data['message'] ?? $err));
                if (str_contains($message, 'valid domain')) {
                    $invalid = array_merge($invalid, $chunk);
                }
                continue;
            }

            $seen = [];
            foreach ($data['results'] ?? [] as $result) {
                $domain = strtolower((string) ($result['domainName'] ?? ''));
                if ($domain === '') {
                    continue;
                }

                $seen[$domain] = true;
                if (!empty($result['purchasable'])) {
                    $available[] = $domain;
                } else {
                    $unavailable[] = $domain;
                }
            }

            foreach ($chunk as $domain) {
                if (!isset($seen[$domain])) {
                    $invalid[] = $domain;
                }
            }
        }

        return [
            'ok' => $ok,
            'available' => array_values(array_unique($available)),
            'unavailable' => array_values(array_unique($unavailable)),
            'invalid' => array_values(array_unique($invalid)),
            'raw' => $raw,
        ];
    }

    public function registerDomain(string $domain, array $opts): array
    {
        $contacts = $this->normalizeContacts($opts);
        $domainData = [
            'domainName' => $domain,
            'nameservers' => array_values($opts['nameservers'] ?? []),
            'contacts' => $contacts,
            'autorenewEnabled' => (bool) ($opts['auto_renew'] ?? false),
        ];

        [$code, $data, $err] = $this->request('POST', '/core/v1/domains', [
            'domain' => $domainData,
            'years' => (int) ($opts['years'] ?? 1),
            ...isset($opts['purchase_price']) ? ['purchasePrice' => (float) $opts['purchase_price']] : [],
        ]);

        $result = $this->result($code, $data, $err);

        if ($result['ok'] && !empty($opts['privacy'])) {
            $privacy = $this->purchasePrivacy(
                $domain,
                isset($opts['privacy_price']) ? (float) $opts['privacy_price'] : null
            );
            $result['privacy'] = $privacy;
            $result['privacy_ok'] = !empty($privacy['ok']);
        }

        return $result;
    }

    public function renewDomain(string $domain, int $years = 1, array $opts = []): array
    {
        $body = ['years' => $years];
        if (isset($opts['purchase_price'])) {
            $body['purchasePrice'] = (float) $opts['purchase_price'];
        }

        [$code, $data, $err] = $this->request(
            'POST',
            '/core/v1/domains/' . rawurlencode($domain) . ':renew',
            $body
        );

        return $this->result($code, $data, $err);
    }

    public function transferDomain(string $domain, array $opts): array
    {
        $body = [
            'domainName' => $domain,
            'authCode' => (string) ($opts['auth_code'] ?? $opts['authCode'] ?? ''),
        ];
        if (isset($opts['purchase_price'])) {
            $body['purchasePrice'] = (float) $opts['purchase_price'];
        }

        [$code, $data, $err] = $this->request('POST', '/core/v1/transfers', $body);

        return $this->result($code, $data, $err);
    }

    public function getDomain(string $domain): array
    {
        [$code, $data, $err] = $this->request(
            'GET',
            '/core/v1/domains/' . rawurlencode($domain)
        );

        $result = $this->result($code, $data, $err);
        if ($result['ok']) {
            $result += [
                'domain' => $data['domainName'] ?? $domain,
                'created_at' => $data['createDate'] ?? null,
                'expires_at' => $data['expireDate'] ?? null,
                'auto_renew' => isset($data['autorenewEnabled']) ? (bool) $data['autorenewEnabled'] : null,
                'locked' => isset($data['locked']) ? (bool) $data['locked'] : null,
                'privacy' => isset($data['privacyEnabled']) ? (bool) $data['privacyEnabled'] : null,
                'nameservers' => $data['nameservers'] ?? [],
            ];
        }

        return $result;
    }

    public function getDNS(string $domain): array
    {
        $records = [];
        $raw = [];
        $page = 1;
        $ok = true;
        $error = '';

        do {
            [$code, $data, $err] = $this->request(
                'GET',
                '/core/v1/domains/' . rawurlencode($domain) . '/records?perPage=1000&page=' . $page
            );
            $raw[] = $data;

            if ($err !== '' || $code >= 400) {
                $ok = false;
                $error = $err !== '' ? $err : (string) ($data['message'] ?? 'Name.com DNS request failed');
                break;
            }

            foreach ($data['records'] ?? [] as $record) {
                $records[] = [
                    'record_id' => isset($record['id']) ? (string) $record['id'] : null,
                    'type' => strtoupper((string) ($record['type'] ?? '')),
                    'host' => (string) ($record['host'] ?? '@'),
                    'value' => (string) ($record['answer'] ?? ''),
                    'ttl' => (int) ($record['ttl'] ?? 300),
                    'prio' => isset($record['priority']) ? (int) $record['priority'] : null,
                ];
            }

            $next = $data['nextPage'] ?? null;
            $page = $next ? (int) $next : 0;
        } while ($page > 0);

        return [
            'ok' => $ok,
            'records' => $records,
            'raw' => $raw,
            'err' => $error,
        ];
    }

    public function setDNS(string $domain, array $records): array
    {
        $current = $this->getDNS($domain);
        if (!$current['ok']) {
            return $current;
        }

        $operations = [];
        foreach ($current['records'] as $record) {
            if (!empty($record['record_id'])) {
                $operations[] = $this->delDNS($domain, ['record_id' => $record['record_id']]);
            }
        }

        foreach ($records as $record) {
            $operations[] = $this->addDNS($domain, $record);
        }

        return [
            'ok' => !array_filter($operations, static fn (array $op): bool => empty($op['ok'])),
            'raw' => $operations,
        ];
    }

    public function addDNS(string $domain, array $record): array
    {
        $body = $this->dnsRecordToProvider($record);
        [$code, $data, $err] = $this->request(
            'POST',
            '/core/v1/domains/' . rawurlencode($domain) . '/records',
            $body
        );

        $result = $this->result($code, $data, $err);
        if ($result['ok'] && isset($data['id'])) {
            $result['record_id'] = (string) $data['id'];
        }

        return $result;
    }

    public function updateDNS(string $domain, string|int $recordId, array $record): array
    {
        [$code, $data, $err] = $this->request(
            'PUT',
            '/core/v1/domains/' . rawurlencode($domain) . '/records/' . rawurlencode((string) $recordId),
            $this->dnsRecordToProvider($record)
        );

        return $this->result($code, $data, $err);
    }

    public function delDNS(string $domain, array $selector): array
    {
        if (isset($selector['record_id'])) {
            [$code, $data, $err] = $this->request(
                'DELETE',
                '/core/v1/domains/' . rawurlencode($domain) . '/records/' . rawurlencode((string) $selector['record_id'])
            );

            return $this->result($code, $data, $err);
        }

        $current = $this->getDNS($domain);
        if (!$current['ok']) {
            return $current;
        }

        $deleted = [];
        foreach ($current['records'] as $record) {
            if (!$this->dnsMatches($record, $selector) || empty($record['record_id'])) {
                continue;
            }
            $deleted[] = $this->delDNS($domain, ['record_id' => $record['record_id']]);
        }

        return [
            'ok' => $deleted !== [] && !array_filter($deleted, static fn (array $op): bool => empty($op['ok'])),
            'deleted' => count($deleted),
            'raw' => $deleted,
        ];
    }

    public function setNameServers(string $domain, array $nameservers): array
    {
        [$code, $data, $err] = $this->request(
            'POST',
            '/core/v1/domains/' . rawurlencode($domain) . ':setNameservers',
            ['nameservers' => array_values($nameservers)]
        );

        return $this->result($code, $data, $err);
    }

    public function getAuthCode(string $domain): array
    {
        [$code, $data, $err] = $this->request(
            'GET',
            '/core/v1/domains/' . rawurlencode($domain) . ':getAuthCode'
        );

        $result = $this->result($code, $data, $err);
        if ($result['ok']) {
            $result['auth_code'] = $data['authCode'] ?? null;
        }

        return $result;
    }

    public function setAutoRenew(string $domain, bool $enabled): array
    {
        [$code, $data, $err] = $this->request(
            'PATCH',
            '/core/v1/domains/' . rawurlencode($domain),
            ['autorenewEnabled' => $enabled]
        );

        return $this->result($code, $data, $err);
    }

    public function checkTransferStatus(string $domain): array
    {
        [$code, $data, $err] = $this->request(
            'GET',
            '/core/v1/transfers/' . rawurlencode($domain)
        );

        return $this->result($code, $data, $err) + [
            'status' => $data['status'] ?? null,
            'reason' => $data['statusDetails'] ?? null,
            'created_at' => $data['created'] ?? null,
        ];
    }

    public function getPrice(string $domain, int $years = 1): array
    {
        [$code, $data, $err] = $this->request(
            'GET',
            '/core/v1/domains/' . rawurlencode($domain) . ':getPricing?years=' . $years
        );

        return $this->result($code, $data, $err) + [
            'registration' => isset($data['purchasePrice']) ? (float) $data['purchasePrice'] : null,
            'renewal' => isset($data['renewalPrice']) ? (float) $data['renewalPrice'] : null,
            'transfer' => isset($data['transferPrice']) ? (float) $data['transferPrice'] : null,
            'premium' => !empty($data['premium']),
        ];
    }

    public function tlds(): array
    {
        $tlds = [];
        $page = 1;

        do {
            [$code, $data] = $this->request(
                'GET',
                '/core/v1/tldpricing?perPage=1000&page=' . $page
            );
            if ($code >= 400) {
                break;
            }

            foreach ($data['pricing'] ?? [] as $pricing) {
                if (isset($pricing['tld'])) {
                    $tlds[] = (string) $pricing['tld'];
                }
            }

            $next = $data['nextPage'] ?? null;
            $page = $next ? (int) $next : 0;
        } while ($page > 0);

        return array_values(array_unique($tlds));
    }

    public function suggest(
        array|string $query,
        array $tlds = [],
        ?int $limit = null,
        ?string $filterType = null,
        ?float $priceMax = null,
        ?float $priceMin = null
    ): array {
        $body = ['keyword' => is_array($query) ? implode(' ', $query) : $query];

        if ($tlds !== []) {
            $body['tldFilter'] = array_map(static fn ($tld): string => ltrim((string) $tld, '.'), $tlds);
        }
        if ($limit !== null) {
            $body['limit'] = $limit;
        }

        [$code, $data, $err] = $this->request('POST', '/core/v1/domains:search', $body);
        if ($err !== '' || $code >= 400) {
            return [];
        }

        $items = [];
        foreach ($data['results'] ?? [] as $item) {
            $domain = (string) ($item['domainName'] ?? '');
            if ($domain === '') {
                continue;
            }

            $price = isset($item['purchasePrice']) ? (float) $item['purchasePrice'] : null;
            $purchaseType = (string) ($item['purchaseType'] ?? 'registration');
            $premium = !empty($item['premium']) || ($purchaseType !== '' && $purchaseType !== 'registration');

            if ($filterType === 'premium' && !$premium) {
                continue;
            }
            if ($filterType === 'suggestion' && $premium) {
                continue;
            }
            if ($price !== null && $priceMin !== null && $price < $priceMin) {
                continue;
            }
            if ($price !== null && $priceMax !== null && $price > $priceMax) {
                continue;
            }

            $items[$domain] = [
                'available' => !empty($item['purchasable']),
                'price' => $price,
                'renewal_price' => isset($item['renewalPrice']) ? (float) $item['renewalPrice'] : null,
                'premium' => $premium,
                'type' => $premium ? 'premium' : 'suggestion',
            ];

            if ($limit !== null && count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function purchasePrivacy(string $domain, ?float $purchasePrice = null): array
    {
        $body = [];
        if ($purchasePrice !== null) {
            $body['purchasePrice'] = $purchasePrice;
        }

        [$code, $data, $err] = $this->request(
            'POST',
            '/core/v1/domains/' . rawurlencode($domain) . ':purchasePrivacy',
            $body
        );

        return $this->result($code, $data, $err);
    }

    public function getDNSSEC(string $domain): array
    {
        [$code, $data, $err] = $this->request(
            'GET',
            '/core/v1/domains/' . rawurlencode($domain) . '/dnssec'
        );

        return $this->result($code, $data, $err);
    }

    public function addDNSSEC(string $domain, array $ds): array
    {
        [$code, $data, $err] = $this->request(
            'POST',
            '/core/v1/domains/' . rawurlencode($domain) . '/dnssec',
            $ds
        );

        return $this->result($code, $data, $err);
    }

    public function deleteDNSSEC(string $domain, string $digest): array
    {
        [$code, $data, $err] = $this->request(
            'DELETE',
            '/core/v1/domains/' . rawurlencode($domain) . '/dnssec/' . rawurlencode($digest)
        );

        return $this->result($code, $data, $err);
    }

    public function listVanityNameservers(string $domain): array
    {
        [$code, $data, $err] = $this->request(
            'GET',
            '/core/v1/domains/' . rawurlencode($domain) . '/vanity_nameservers?perPage=1000'
        );

        return $this->result($code, $data, $err);
    }

    public function createVanityNameserver(string $domain, string $hostname, array $ips): array
    {
        [$code, $data, $err] = $this->request(
            'POST',
            '/core/v1/domains/' . rawurlencode($domain) . '/vanity_nameservers',
            ['hostname' => $hostname, 'ips' => array_values($ips)]
        );

        return $this->result($code, $data, $err);
    }

    public function updateVanityNameserver(string $domain, string $hostname, array $ips): array
    {
        [$code, $data, $err] = $this->request(
            'PUT',
            '/core/v1/domains/' . rawurlencode($domain) . '/vanity_nameservers/' . rawurlencode($hostname),
            ['ips' => array_values($ips)]
        );

        return $this->result($code, $data, $err);
    }

    public function deleteVanityNameserver(string $domain, string $hostname): array
    {
        [$code, $data, $err] = $this->request(
            'DELETE',
            '/core/v1/domains/' . rawurlencode($domain) . '/vanity_nameservers/' . rawurlencode($hostname)
        );

        return $this->result($code, $data, $err);
    }

    public function raw(string $op, array $params = []): array
    {
        $method = strtoupper((string) ($params['_method'] ?? 'GET'));
        $body = $params['_body'] ?? null;
        unset($params['_method'], $params['_body']);

        $path = str_starts_with($op, '/') ? $op : '/core/v1/' . ltrim($op, '/');

        if ($method === 'GET' && $params !== []) {
            $path .= (str_contains($path, '?') ? '&' : '?') . http_build_query($params);
            $params = [];
        } elseif ($body === null && $params !== []) {
            $body = $params;
        }

        [$code, $data, $err] = $this->request($method, $path, is_array($body) ? $body : null);

        return $this->result($code, $data, $err);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->base . $path;
        $headers = [
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->token),
            'Accept: application/json',
        ];

        [$code, $response, $err] = match ($method) {
            'POST' => Http::postJson($url, $body ?? [], $headers),
            'PUT' => Http::putJson($url, $body ?? [], $headers),
            'PATCH' => Http::patchJson($url, $body ?? [], $headers),
            'DELETE' => Http::delete($url, $headers),
            default => Http::get($url, $headers),
        };

        $data = [];
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            $data = is_array($decoded) ? $decoded : ['_raw' => $response];
        }

        return [(int) $code, $data, (string) $err];
    }

    private function result(int $code, array $data, string $err): array
    {
        return [
            'ok' => $code > 0 && $code < 400 && $err === '',
            'raw' => $data,
            'http' => $code,
            'err' => $err !== '' ? $err : ($code >= 400 ? (string) ($data['message'] ?? 'HTTP ' . $code) : ''),
        ];
    }

    private function normalizeContacts(array $opts): array
    {
        $contacts = $opts['contacts'] ?? [];
        if ($contacts === [] && isset($opts['registrant'])) {
            $contacts = ['registrant' => $opts['registrant']];
        }
        if ($contacts === []) {
            throw new \InvalidArgumentException('Name.com registration requires registrant contact data');
        }

        $default = $contacts['registrant'] ?? $contacts['owner'] ?? reset($contacts);
        if (!is_array($default)) {
            throw new \InvalidArgumentException('Invalid Name.com contact data');
        }

        $result = [];
        foreach (['registrant', 'admin', 'tech', 'billing'] as $type) {
            $contact = $contacts[$type] ?? $default;
            $result[$type] = $this->formatContact((array) $contact);
        }

        return $result;
    }

    private function formatContact(array $contact): array
    {
        $pick = static function (array $data, array $keys, string $default = ''): string {
            foreach ($keys as $key) {
                if (isset($data[$key])) {
                    return (string) $data[$key];
                }
            }
            return $default;
        };

        return [
            'firstName' => $pick($contact, ['firstName', 'first_name', 'firstname']),
            'lastName' => $pick($contact, ['lastName', 'last_name', 'lastname']),
            'companyName' => $pick($contact, ['companyName', 'company', 'org', 'organization']),
            'email' => $pick($contact, ['email']),
            'phone' => $pick($contact, ['phone']),
            'address1' => $pick($contact, ['address1', 'address']),
            'address2' => $pick($contact, ['address2']),
            'city' => $pick($contact, ['city']),
            'state' => $pick($contact, ['state', 'province']),
            'zip' => $pick($contact, ['zip', 'postal_code', 'postalcode']),
            'country' => strtoupper($pick($contact, ['country'])),
        ];
    }

    private function dnsRecordToProvider(array $record): array
    {
        $data = [
            'host' => (string) ($record['host'] ?? '@'),
            'type' => strtoupper((string) ($record['type'] ?? '')),
            'answer' => (string) ($record['value'] ?? $record['answer'] ?? ''),
            'ttl' => max(300, (int) ($record['ttl'] ?? 300)),
        ];

        if (isset($record['prio']) || isset($record['priority'])) {
            $data['priority'] = (int) ($record['prio'] ?? $record['priority']);
        }

        return $data;
    }

    private function dnsMatches(array $record, array $selector): bool
    {
        foreach (['type', 'host', 'value'] as $field) {
            if (!array_key_exists($field, $selector)) {
                continue;
            }
            $left = $field === 'type'
                ? strtoupper((string) ($record[$field] ?? ''))
                : (string) ($record[$field] ?? '');
            $right = $field === 'type'
                ? strtoupper((string) $selector[$field])
                : (string) $selector[$field];

            if ($left !== $right) {
                return false;
            }
        }

        return true;
    }
}
