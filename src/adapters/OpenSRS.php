<?php

namespace Namingo\Cardo\DRS\Adapters;

use Namingo\Cardo\DRS\Core\BaseAdapter;
use Namingo\Cardo\DRS\Core\Http;

final class OpenSRS extends BaseAdapter
{
    protected string $brand = 'opensrs';

    private string $apiKey;
    private string $username;
    private string $password;
    private string $endpoint;

    public function __construct(array $creds)
    {
        parent::__construct($creds);
        $this->apiKey = (string) ($creds['api_key'] ?? $creds['key'] ?? '');
        $this->username = (string) ($creds['username'] ?? $creds['api_user'] ?? '');
        $this->password = (string) ($creds['password'] ?? $creds['reg_password'] ?? '');
        $this->endpoint = (string) ($creds['base'] ?? $creds['endpoint'] ?? 'https://horizon.opensrs.net:55443');

        if ($this->apiKey === '' || $this->username === '' || $this->password === '') {
            throw new \InvalidArgumentException('OpenSRS requires api_key, username and password');
        }
        if (!str_starts_with($this->endpoint, 'https://')) {
            $this->endpoint = 'https://' . preg_replace('#^https?://#', '', $this->endpoint);
        }
    }

    public function checkAvailability(array $domains): array
    {
        $available = $unavailable = $invalid = [];
        $raw = [];
        foreach (array_values(array_unique($domains)) as $domain) {
            $domain = strtolower(trim((string) $domain));
            $r = $this->call('DOMAIN', 'LOOKUP', ['domain' => $domain]);
            $raw[$domain] = $r;
            $code = (int) ($r['response_code'] ?? 0);
            if ($code === 210) $available[] = $domain;
            elseif ($code > 0 && $code < 300) $unavailable[] = $domain;
            else $invalid[] = $domain;
        }
        return ['ok' => $invalid === [], 'available' => $available, 'unavailable' => $unavailable, 'invalid' => $invalid, 'raw' => $raw];
    }

    public function registerDomain(string $domain, array $opts): array
    {
        $nameservers = array_values($opts['nameservers'] ?? []);
        $attrs = [
            'domain' => $domain,
            'period' => (int) ($opts['years'] ?? 1),
            'contact_set' => $this->contacts($opts),
            'custom_tech_contact' => 0,
            'custom_nameservers' => $nameservers === [] ? 0 : 1,
            'reg_username' => $this->username,
            'reg_password' => $this->password,
            'reg_type' => 'new',
            'handle' => (string) ($opts['handle'] ?? 'process'),
            'f_whois_privacy' => !empty($opts['privacy']) ? 1 : 0,
            'auto_renew' => !empty($opts['auto_renew']) ? 1 : 0,
        ];
        if ($nameservers !== []) $attrs['nameserver_list'] = $this->nameservers($nameservers);
        if (isset($opts['purchase_price'])) $attrs['premium_price_to_verify'] = (float) $opts['purchase_price'];

        return $this->result($this->call('DOMAIN', 'SW_REGISTER', $attrs));
    }

    public function renewDomain(string $domain, int $years = 1, array $opts = []): array
    {
        $info = $this->getDomain($domain);
        if (!$info['ok'] || empty($info['expires_at'])) {
            return ['ok' => false, 'err' => 'Could not determine current expiration date', 'raw' => $info];
        }
        $ts = strtotime((string) $info['expires_at']);
        if ($ts === false) return ['ok' => false, 'err' => 'Invalid expiration date', 'raw' => $info];

        $attrs = [
            'domain' => $domain,
            'currentexpirationyear' => (int) gmdate('Y', $ts),
            'period' => $years,
            'handle' => (string) ($opts['handle'] ?? 'process'),
            'auto_renew' => !empty($opts['auto_renew']) ? 1 : 0,
        ];
        if (isset($opts['purchase_price'])) $attrs['premium_price_to_verify'] = (float) $opts['purchase_price'];

        return $this->result($this->call('DOMAIN', 'RENEW', $attrs));
    }

    public function transferDomain(string $domain, array $opts): array
    {
        $attrs = [
            'domain' => $domain,
            'period' => (int) ($opts['years'] ?? 1),
            'reg_username' => $this->username,
            'reg_password' => $this->password,
            'reg_type' => 'transfer',
            'handle' => (string) ($opts['handle'] ?? 'process'),
            'auth_info' => (string) ($opts['auth_code'] ?? $opts['authCode'] ?? ''),
        ];
        if (isset($opts['purchase_price'])) $attrs['premium_price_to_verify'] = (float) $opts['purchase_price'];

        return $this->result($this->call('DOMAIN', 'SW_REGISTER', $attrs));
    }

    public function getDomain(string $domain): array
    {
        $r = $this->call('DOMAIN', 'GET', ['type' => 'all_info', 'clean_ca_subset' => 1], $domain);
        $out = $this->result($r);
        if (!$out['ok']) return $out;

        $a = (array) ($r['attributes'] ?? []);
        $ns = [];
        foreach ((array) ($a['nameserver_list'] ?? []) as $item) {
            if (is_array($item) && !empty($item['name'])) $ns[] = (string) $item['name'];
        }

        return $out + [
            'domain' => $domain,
            'created_at' => $a['registry_createdate'] ?? null,
            'expires_at' => $a['registry_expiredate'] ?? $a['expiredate'] ?? null,
            'auto_renew' => isset($a['auto_renew']) ? ((string) $a['auto_renew'] === '1') : null,
            'nameservers' => $ns,
        ];
    }

    public function getDNS(string $domain): array
    {
        $r = $this->call('DOMAIN', 'GET_DNS_ZONE', ['domain' => $domain]);
        $out = $this->result($r);
        $records = [];
        if ($out['ok']) {
            foreach ((array) ($r['attributes']['records'] ?? []) as $type => $items) {
                foreach ((array) $items as $item) {
                    if (!is_array($item)) continue;
                    $record = $this->fromDns((string) $type, $item);
                    if ($record !== null) $records[] = $record;
                }
            }
        }
        return $out + ['records' => $records, 'nameservers_ok' => isset($r['attributes']['nameservers_ok']) ? ((string) $r['attributes']['nameservers_ok'] === '1') : null];
    }

    public function setDNS(string $domain, array $records): array
    {
        $grouped = [];
        foreach ($records as $record) {
            $type = strtoupper((string) ($record['type'] ?? ''));
            $converted = $this->toDns($record);
            if ($converted === null) return ['ok' => false, 'err' => 'Unsupported DNS type: ' . $type, 'raw' => $record];
            $grouped[$type][] = $converted;
        }
        return $this->result($this->call('DOMAIN', 'SET_DNS_ZONE', ['domain' => $domain, 'nameservers_ok' => 1, 'records' => $grouped]));
    }

    public function addDNS(string $domain, array $record): array
    {
        $current = $this->getDNS($domain);
        if (!$current['ok']) return $current;
        $records = $current['records'];
        $records[] = $record;
        return $this->setDNS($domain, $records);
    }

    public function delDNS(string $domain, array $selector): array
    {
        $current = $this->getDNS($domain);
        if (!$current['ok']) return $current;
        $kept = [];
        $deleted = 0;
        foreach ($current['records'] as $record) {
            if ($this->matches($record, $selector)) $deleted++;
            else $kept[] = $record;
        }
        if ($deleted === 0) return ['ok' => false, 'err' => 'No matching DNS record found', 'deleted' => 0, 'raw' => $current];
        $out = $this->setDNS($domain, $kept);
        $out['deleted'] = $deleted;
        return $out;
    }

    public function setNameServers(string $domain, array $nameservers): array
    {
        return $this->result($this->call('DOMAIN', 'ADVANCED_UPDATE_NAMESERVERS', [
            'assign_ns' => array_values($nameservers),
            'op_type' => 'assign',
        ], $domain));
    }

    public function getAuthCode(string $domain): array
    {
        $r = $this->call('DOMAIN', 'GET', ['type' => 'domain_auth_info'], $domain);
        return $this->result($r) + ['auth_code' => $r['attributes']['domain_auth_info'] ?? null];
    }

    public function setAutoRenew(string $domain, bool $enabled): array
    {
        return $this->result($this->call('DOMAIN', 'MODIFY', [
            'data' => 'expire_action',
            'affect_domains' => 0,
            'auto_renew' => $enabled ? 1 : 0,
            'let_expire' => $enabled ? 0 : 1,
        ], $domain));
    }

    public function checkTransferStatus(string $domain): array
    {
        $r = $this->call('DOMAIN', 'CHECK_TRANSFER', [
            'domain' => $domain,
            'check_status' => 1,
            'get_request_address' => 0,
        ]);
        return $this->result($r) + [
            'status' => $r['attributes']['status'] ?? null,
            'reason' => $r['attributes']['reason'] ?? null,
            'timestamp' => $r['attributes']['timestamp'] ?? null,
        ];
    }

    public function getPrice(string $domain, int $years = 1, string $regType = 'new'): array
    {
        $r = $this->call('DOMAIN', 'GET_PRICE', ['domain' => $domain, 'period' => $years, 'reg_type' => $regType]);
        return $this->result($r) + ['price' => isset($r['attributes']['price']) ? (float) $r['attributes']['price'] : null];
    }

    public function cancelPendingOrders(): array
    {
        return $this->result($this->call('ORDER', 'CANCEL_PENDING_ORDERS', ['to_date' => time(), 'status' => ['declined', 'pending']]));
    }

    public function createDNSZone(string $domain, ?string $template = null): array
    {
        $attrs = ['domain' => $domain];
        if ($template !== null) $attrs['dns_template'] = $template;
        return $this->result($this->call('DOMAIN', 'CREATE_DNS_ZONE', $attrs));
    }

    public function resetDNSZone(string $domain, ?string $template = null): array
    {
        $attrs = ['domain' => $domain];
        if ($template !== null) $attrs['dns_template'] = $template;
        return $this->result($this->call('DOMAIN', 'RESET_DNS_ZONE', $attrs));
    }

    public function deleteDNSZone(string $domain): array
    {
        return $this->result($this->call('DOMAIN', 'DELETE_DNS_ZONE', ['domain' => $domain]));
    }

    public function createNameserver(string $domain, string $hostname, ?string $ipv4 = null, ?string $ipv6 = null): array
    {
        $attrs = ['domain' => $domain, 'name' => $hostname, 'add_to_all_registry' => 1];
        if ($ipv4 !== null) $attrs['ipaddress'] = $ipv4;
        if ($ipv6 !== null) $attrs['ipv6'] = $ipv6;
        return $this->result($this->call('NAMESERVER', 'CREATE', $attrs));
    }

    public function updateNameserver(string $domain, string $hostname, ?string $ipv4 = null, ?string $ipv6 = null): array
    {
        $attrs = ['domain' => $domain, 'name' => $hostname];
        if ($ipv4 !== null) $attrs['ipaddress'] = $ipv4;
        if ($ipv6 !== null) $attrs['ipv6'] = $ipv6;
        return $this->result($this->call('NAMESERVER', 'MODIFY', $attrs));
    }

    public function deleteNameserver(string $domain, string $hostname, ?string $ipv4 = null, ?string $ipv6 = null): array
    {
        $attrs = ['domain' => $domain, 'name' => $hostname];
        if ($ipv4 !== null) $attrs['ipaddress'] = $ipv4;
        if ($ipv6 !== null) $attrs['ipv6'] = $ipv6;
        return $this->result($this->call('NAMESERVER', 'DELETE', $attrs));
    }

    public function raw(string $op, array $params = []): array
    {
        $object = strtoupper((string) ($params['_object'] ?? 'DOMAIN'));
        $action = strtoupper((string) ($params['_action'] ?? $op));
        $domain = isset($params['_domain']) ? (string) $params['_domain'] : null;
        unset($params['_object'], $params['_action'], $params['_domain']);

        if (str_contains($op, ':')) {
            [$object, $action] = array_map('strtoupper', explode(':', $op, 2));
        }

        return $this->result($this->call($object, $action, $params, $domain));
    }

    private function call(string $object, string $action, array $attributes, ?string $domain = null): array
    {
        $xml = $this->envelope($object, $action, $attributes, $domain);
        $signature = md5(md5($xml . $this->apiKey) . $this->apiKey);

        [$http, $body, $err] = Http::postRaw($this->endpoint, $xml, [
            'Content-Type: text/xml',
            'X-Username: ' . $this->username,
            'X-Signature: ' . $signature,
            'Accept: text/xml',
        ], 60);

        if ($err !== '') return ['_transport_ok' => false, '_http' => $http, '_error' => $err, '_raw_xml' => (string) $body];

        try {
            $parsed = $this->parse((string) $body);
        } catch (\Throwable $e) {
            return ['_transport_ok' => false, '_http' => $http, '_error' => $e->getMessage(), '_raw_xml' => (string) $body];
        }

        $parsed['_transport_ok'] = $http > 0 && $http < 400;
        $parsed['_http'] = $http;
        $parsed['_raw_xml'] = (string) $body;
        return $parsed;
    }

    private function result(array $r): array
    {
        $code = (int) ($r['response_code'] ?? 0);
        $ok = !empty($r['_transport_ok']) && $code > 0 && $code < 300;
        return [
            'ok' => $ok,
            'code' => $code ?: null,
            'text' => $r['response_text'] ?? null,
            'raw' => $r,
            'http' => $r['_http'] ?? null,
            'err' => $r['_error'] ?? (!$ok ? (string) ($r['response_text'] ?? 'OpenSRS command failed') : ''),
        ];
    }

    private function envelope(string $object, string $action, array $attributes, ?string $domain): string
    {
        $items = $this->item('protocol', 'XCP') . $this->item('object', strtoupper($object)) . $this->item('action', strtoupper($action));
        if ($domain !== null) $items .= $this->item('domain', $domain);
        $items .= $this->item('attributes', $attributes);

        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
            . '<!DOCTYPE OPS_envelope SYSTEM "ops.dtd">'
            . '<OPS_envelope><header><version>0.9</version></header><body><data_block><dt_assoc>'
            . $items
            . '</dt_assoc></data_block></body></OPS_envelope>';
    }

    private function item(string $key, mixed $value): string
    {
        $key = htmlspecialchars($key, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        if (is_array($value)) {
            $tag = array_is_list($value) ? 'dt_array' : 'dt_assoc';
            $inner = '';
            foreach ($value as $k => $v) $inner .= $this->item((string) $k, $v);
            return '<item key="' . $key . '"><' . $tag . '>' . $inner . '</' . $tag . '></item>';
        }
        return '<item key="' . $key . '">' . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</item>';
    }

    private function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false) throw new \RuntimeException('Invalid OpenSRS XML response');

        $assoc = $doc->body->data_block->dt_assoc ?? null;
        if ($assoc === null) throw new \RuntimeException('Unexpected OpenSRS XML envelope');

        $out = [];
        foreach ($assoc->item as $item) $out[(string) $item['key']] = $this->xmlValue($item);
        return $out;
    }

    private function xmlValue(\SimpleXMLElement $item): mixed
    {
        if (isset($item->dt_assoc)) {
            $out = [];
            foreach ($item->dt_assoc->item as $child) $out[(string) $child['key']] = $this->xmlValue($child);
            return $out;
        }
        if (isset($item->dt_array)) {
            $out = [];
            foreach ($item->dt_array->item as $child) $out[] = $this->xmlValue($child);
            return $out;
        }
        return (string) $item;
    }

    private function contacts(array $opts): array
    {
        $contacts = $opts['contacts'] ?? [];
        if ($contacts === [] && isset($opts['registrant'])) $contacts = ['owner' => $opts['registrant']];
        if (isset($contacts['registrant']) && !isset($contacts['owner'])) $contacts['owner'] = $contacts['registrant'];
        if ($contacts === []) throw new \InvalidArgumentException('OpenSRS registration requires contact data');

        $default = $contacts['owner'] ?? reset($contacts);
        $out = [];
        foreach (['owner', 'admin', 'tech', 'billing'] as $type) $out[$type] = $this->contact((array) ($contacts[$type] ?? $default));
        return $out;
    }

    private function contact(array $c): array
    {
        $pick = static function (array $d, array $keys, string $default = ''): string {
            foreach ($keys as $key) if (isset($d[$key])) return (string) $d[$key];
            return $default;
        };
        return [
            'first_name' => $pick($c, ['first_name', 'firstname', 'firstName']),
            'last_name' => $pick($c, ['last_name', 'lastname', 'lastName']),
            'email' => $pick($c, ['email']),
            'phone' => $pick($c, ['phone']),
            'address1' => $pick($c, ['address1', 'address']),
            'city' => $pick($c, ['city']),
            'state' => $pick($c, ['state', 'province']),
            'postal_code' => $pick($c, ['postal_code', 'postalcode', 'zip']),
            'country' => strtoupper($pick($c, ['country'])),
            'owner' => $pick($c, ['owner'], 'individual'),
            'org_name' => $pick($c, ['org_name', 'org', 'company', 'organization']),
        ];
    }

    private function nameservers(array $servers): array
    {
        $out = [];
        foreach (array_values($servers) as $i => $server) $out[] = ['name' => (string) $server, 'sortorder' => $i];
        return $out;
    }

    private function fromDns(string $type, array $r): ?array
    {
        $type = strtoupper($type);
        $host = (string) ($r['subdomain'] ?? '@');
        if ($host === '') $host = '@';
        return match ($type) {
            'A' => ['type' => 'A', 'host' => $host, 'value' => (string) ($r['ip_address'] ?? ''), 'ttl' => 3600, 'prio' => null],
            'AAAA' => ['type' => 'AAAA', 'host' => $host, 'value' => (string) ($r['ipv6_address'] ?? ''), 'ttl' => 3600, 'prio' => null],
            'CNAME' => ['type' => 'CNAME', 'host' => $host, 'value' => (string) ($r['hostname'] ?? ''), 'ttl' => 3600, 'prio' => null],
            'MX' => ['type' => 'MX', 'host' => $host, 'value' => (string) ($r['hostname'] ?? ''), 'ttl' => 3600, 'prio' => (int) ($r['priority'] ?? 0)],
            'TXT' => ['type' => 'TXT', 'host' => $host, 'value' => (string) ($r['text'] ?? ''), 'ttl' => 3600, 'prio' => null],
            'SRV' => ['type' => 'SRV', 'host' => $host, 'value' => (string) ($r['hostname'] ?? ''), 'ttl' => 3600, 'prio' => (int) ($r['priority'] ?? 0), 'weight' => (int) ($r['weight'] ?? 0), 'port' => (int) ($r['port'] ?? 0)],
            default => null,
        };
    }

    private function toDns(array $r): ?array
    {
        $type = strtoupper((string) ($r['type'] ?? ''));
        $host = (string) ($r['host'] ?? '@');
        $sub = $host === '@' ? '' : $host;
        $value = (string) ($r['value'] ?? '');
        return match ($type) {
            'A' => ['subdomain' => $sub, 'ip_address' => $value],
            'AAAA' => ['subdomain' => $sub, 'ipv6_address' => $value],
            'CNAME' => ['subdomain' => $sub, 'hostname' => $value],
            'MX' => ['subdomain' => $sub, 'hostname' => $value, 'priority' => (int) ($r['prio'] ?? $r['priority'] ?? 0)],
            'TXT' => ['subdomain' => $sub, 'text' => $value],
            'SRV' => ['subdomain' => $sub, 'hostname' => $value, 'port' => (int) ($r['port'] ?? 0), 'priority' => (int) ($r['prio'] ?? $r['priority'] ?? 0), 'weight' => (int) ($r['weight'] ?? 0)],
            default => null,
        };
    }

    private function matches(array $record, array $selector): bool
    {
        foreach (['type', 'host', 'value', 'prio', 'port', 'weight'] as $field) {
            if (!array_key_exists($field, $selector)) continue;
            $left = $field === 'type' ? strtoupper((string) ($record[$field] ?? '')) : (string) ($record[$field] ?? '');
            $right = $field === 'type' ? strtoupper((string) $selector[$field]) : (string) $selector[$field];
            if ($left !== $right) return false;
        }
        return true;
    }
}
