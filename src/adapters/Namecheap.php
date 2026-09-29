<?php
namespace Namingo\Cardo\DRS\Adapters;

use Namingo\Cardo\DRS\Core\BaseAdapter;
use Namingo\Cardo\DRS\Core\DnsRecord;
use Namingo\Cardo\DRS\Core\Http;

class Namecheap extends BaseAdapter
{
    protected string $brand = 'namecheap';
    protected string $endpoint = 'https://api.namecheap.com/xml.response';
    protected string $apiUser;
    protected string $apiKey;
    protected string $clientIp;

    public function __construct(array $config)
    {
        parent::__construct($config);

        $this->endpoint = $config['base'] ?? $this->endpoint;
        $this->apiUser  = $config['api_user'] ?? '';
        $this->apiKey   = $config['api_key'] ?? '';
        $this->clientIp = $config['client_ip'] ?? '';
    }

    protected function request(string $command, array $params = []): array
    {
        $query = array_merge([
            'ApiUser'   => $this->apiUser,
            'ApiKey'    => $this->apiKey,
            'UserName'  => $this->apiUser,
            'ClientIp'  => $this->clientIp,
            'Command'   => $command,
        ], $params);

        [$code, $body, $err] = Http::request('GET', $this->endpoint, [
            'query' => $query,
        ]);

        if ($err !== '') {
            throw new \RuntimeException('Namecheap transport error: ' . $err);
        }

        if ($code >= 400) {
            throw new \RuntimeException('Namecheap HTTP error: ' . $code);
        }

        return [$code, $this->parseResponse($body)];
    }

    protected function parseResponse(string $xml): \SimpleXMLElement
    {
        $parsed = @simplexml_load_string($xml);
        if ($parsed === false) {
            throw new \RuntimeException('Invalid XML from Namecheap');
        }

        if ((string) $parsed['Status'] !== 'OK') {
            $errors = [];
            foreach ($parsed->Errors->Error as $err) {
                $errors[] = (string) $err;
            }
            throw new \RuntimeException('Namecheap API error: ' . implode('; ', $errors));
        }

        return $parsed;
    }

    public function checkAvailability(array $domains): array
    {
        [$code, $res] = $this->request('namecheap.domains.check', [
            'DomainList' => implode(',', $domains),
        ]);

        $available = [];
        $unavailable = [];
        $invalid = [];

        foreach ($res->CommandResponse->DomainCheckResult as $row) {
            $domain = strtolower((string) $row['Domain']);
            if ($domain === '') {
                continue;
            }

            if (strtolower((string) $row['Available']) === 'true') {
                $available[] = $domain;
            } elseif ((string) $row['ErrorNo'] !== '' && (string) $row['ErrorNo'] !== '0') {
                $invalid[] = $domain;
            } else {
                $unavailable[] = $domain;
            }
        }

        return [
            'ok' => true,
            'available' => $available,
            'unavailable' => $unavailable,
            'invalid' => $invalid,
            'raw' => $this->xmlToArray($res),
            'http' => $code,
            'err' => '',
        ];
    }

    public function registerDomain(string $domain, array $opts): array
    {
        $registrant = (array) ($opts['registrant'] ?? []);

        $params = [
            'DomainName' => $domain,
            'Years' => (int) ($opts['years'] ?? 1),
            'RegistrantFirstName' => $opts['RegistrantFirstName'] ?? $registrant['first_name'] ?? 'John',
            'RegistrantLastName' => $opts['RegistrantLastName'] ?? $registrant['last_name'] ?? 'Doe',
            'RegistrantAddress1' => $opts['RegistrantAddress1'] ?? $registrant['address1'] ?? $registrant['address'] ?? '123 Example Street',
            'RegistrantCity' => $opts['RegistrantCity'] ?? $registrant['city'] ?? 'City',
            'RegistrantStateProvince' => $opts['RegistrantStateProvince'] ?? $registrant['state'] ?? $registrant['province'] ?? 'CA',
            'RegistrantPostalCode' => $opts['RegistrantPostalCode'] ?? $registrant['postal_code'] ?? $registrant['zip'] ?? '90001',
            'RegistrantCountry' => $opts['RegistrantCountry'] ?? $registrant['country'] ?? 'US',
            'RegistrantPhone' => $opts['RegistrantPhone'] ?? $registrant['phone'] ?? '+1.5555555555',
            'RegistrantEmailAddress' => $opts['RegistrantEmailAddress'] ?? $registrant['email'] ?? 'email@example.com',
        ];

        if (!empty($opts['coupon'])) {
            $params['PromotionCode'] = $opts['coupon'];
        }
        if (array_key_exists('privacy', $opts)) {
            $params['AddFreeWhoisguard'] = $opts['privacy'] ? 'yes' : 'no';
            $params['WGEnabled'] = $opts['privacy'] ? 'yes' : 'no';
        }

        [$code, $res] = $this->request('namecheap.domains.create', $params);

        return $this->result($code, $res);
    }

    public function renewDomain(string $domain, int $years = 1, array $opts = []): array
    {
        $params = [
            'DomainName' => $domain,
            'Years' => $years,
        ];

        if (!empty($opts['coupon'])) {
            $params['PromotionCode'] = $opts['coupon'];
        }

        [$code, $res] = $this->request('namecheap.domains.renew', $params);

        return $this->result($code, $res);
    }

    public function transferDomain(string $domain, array $opts): array
    {
        $params = [
            'DomainName' => $domain,
            'Years' => 1,
            'EPPCode' => $opts['auth_code'] ?? $opts['epp_code'] ?? '',
        ];

        if (!empty($opts['coupon'])) {
            $params['PromotionCode'] = $opts['coupon'];
        }
        if (array_key_exists('privacy', $opts)) {
            $params['AddFreeWhoisguard'] = $opts['privacy'] ? 'yes' : 'no';
            $params['WGEnabled'] = $opts['privacy'] ? 'yes' : 'no';
        }

        [$code, $res] = $this->request('namecheap.domains.transfer.create', $params);

        return $this->result($code, $res);
    }

    public function getDomain(string $domain): array
    {
        [$code, $res] = $this->request('namecheap.domains.getInfo', [
            'DomainName' => $domain,
        ]);

        return $this->result($code, $res);
    }

    public function getDNS(string $domain): array
    {
        [$code, $res] = $this->request('namecheap.domains.dns.getHosts', [
            'SLD' => $this->getSLD($domain),
            'TLD' => $this->getTLD($domain),
        ]);

        $records = [];
        foreach ($res->CommandResponse->DomainDNSGetHostsResult->host as $host) {
            $priority = (string) $host['MXPref'];
            $record = (new DnsRecord(
                (string) $host['Type'],
                (string) $host['Name'],
                (string) $host['Address'],
                (int) ((string) $host['TTL'] !== '' ? $host['TTL'] : 1800),
                $priority !== '' ? (int) $priority : null
            ))->toArray();

            if ((string) $host['HostId'] !== '') {
                $record['record_id'] = (string) $host['HostId'];
            }
            if ((string) $host['Flag'] !== '') {
                $record['flag'] = (int) $host['Flag'];
            }
            if ((string) $host['Tag'] !== '') {
                $record['tag'] = (string) $host['Tag'];
            }

            $records[] = $record;
        }

        return [
            'ok' => true,
            'records' => $records,
            'raw' => $this->xmlToArray($res),
            'http' => $code,
            'err' => '',
        ];
    }

    public function setDNS(string $domain, array $records): array
    {
        if ($records === []) {
            return [
                'ok' => false,
                'raw' => [],
                'http' => null,
                'err' => 'Namecheap setHosts requires at least one DNS record',
            ];
        }

        [$code, $res] = $this->request(
            'namecheap.domains.dns.setHosts',
            $this->buildDnsParams($domain, $records)
        );

        return $this->result($code, $res);
    }

    public function addDNS(string $domain, array $record): array
    {
        $current = $this->getDNS($domain);
        if (empty($current['ok'])) {
            return $current;
        }

        $records = $current['records'] ?? [];
        $records[] = $record;

        return $this->setDNS($domain, $records);
    }

    public function delDNS(string $domain, array $selector): array
    {
        if ($selector === []) {
            return ['ok' => false, 'raw' => [], 'http' => null, 'err' => 'Namecheap DNS delete selector is empty'];
        }

        $current = $this->getDNS($domain);
        if (empty($current['ok'])) {
            return $current;
        }

        $remaining = [];
        $deleted = 0;
        foreach ($current['records'] ?? [] as $record) {
            if ($this->dnsMatches($record, $selector)) {
                $deleted++;
                continue;
            }
            $remaining[] = $record;
        }

        if ($deleted === 0) {
            return [
                'ok' => true,
                'deleted' => 0,
                'raw' => $current['raw'] ?? [],
                'http' => $current['http'] ?? null,
                'err' => '',
            ];
        }

        if ($remaining === []) {
            return [
                'ok' => false,
                'deleted' => 0,
                'raw' => $current['raw'] ?? [],
                'http' => $current['http'] ?? null,
                'err' => 'Namecheap does not expose a delete-all-hosts operation through setHosts',
            ];
        }

        $result = $this->setDNS($domain, $remaining);
        $result['deleted'] = !empty($result['ok']) ? $deleted : 0;
        return $result;
    }

    public function setNameServers(string $domain, array $nameservers): array
    {
        [$code, $res] = $this->request('namecheap.domains.dns.setCustom', [
            'SLD' => $this->getSLD($domain),
            'TLD' => $this->getTLD($domain),
            'NameServers' => implode(',', $nameservers),
        ]);

        return $this->result($code, $res);
    }

    public function raw(string $op, array $params = []): array
    {
        [$code, $res] = $this->request($op, $params);

        return $this->result($code, $res);
    }

    // Backwards-compatible provider-specific helpers.
    public function getDNSRecords(string $domain): \SimpleXMLElement
    {
        [, $res] = $this->request('namecheap.domains.dns.getHosts', [
            'SLD' => $this->getSLD($domain),
            'TLD' => $this->getTLD($domain),
        ]);

        return $res;
    }

    public function updateDNSRecords(string $domain, array $records): \SimpleXMLElement
    {
        [, $res] = $this->request(
            'namecheap.domains.dns.setHosts',
            $this->buildDnsParams($domain, $records)
        );

        return $res;
    }

    private function buildDnsParams(string $domain, array $records): array
    {
        $params = [
            'SLD' => $this->getSLD($domain),
            'TLD' => $this->getTLD($domain),
        ];

        foreach (array_values($records) as $index => $record) {
            $i = $index + 1;
            $params["HostName{$i}"] = $record['host'] ?? $record['name'] ?? '@';
            $params["RecordType{$i}"] = strtoupper((string) ($record['type'] ?? 'A'));
            $params["Address{$i}"] = (string) ($record['value'] ?? $record['address'] ?? '');
            $params["TTL{$i}"] = (int) ($record['ttl'] ?? 1800);

            $priority = $record['prio'] ?? $record['priority'] ?? null;
            if ($priority !== null) {
                $params["MXPref{$i}"] = (int) $priority;
            }
            if (isset($record['flag'])) {
                $params["Flag{$i}"] = (int) $record['flag'];
            }
            if (isset($record['tag'])) {
                $params["Tag{$i}"] = (string) $record['tag'];
            }
        }

        return $params;
    }

    private function dnsMatches(array $record, array $selector): bool
    {
        foreach (['record_id', 'type', 'host', 'value'] as $field) {
            if (!array_key_exists($field, $selector)) {
                continue;
            }

            $left = (string) ($record[$field] ?? '');
            $right = (string) $selector[$field];
            if ($field === 'type') {
                $left = strtoupper($left);
                $right = strtoupper($right);
            }

            if ($left !== $right) {
                return false;
            }
        }

        $selectorPriority = $selector['prio'] ?? $selector['priority'] ?? null;
        if ($selectorPriority !== null && (int) ($record['prio'] ?? 0) !== (int) $selectorPriority) {
            return false;
        }

        return true;
    }

    private function result(int $code, \SimpleXMLElement $res): array
    {
        return [
            'ok' => true,
            'raw' => $this->xmlToArray($res),
            'http' => $code,
            'err' => '',
        ];
    }

    private function xmlToArray(\SimpleXMLElement $xml): array
    {
        $json = json_encode($xml);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function getSLD(string $domain): string
    {
        $parts = explode('.', $domain, 2);
        return $parts[0];
    }

    protected function getTLD(string $domain): string
    {
        $parts = explode('.', $domain, 2);
        return $parts[1] ?? '';
    }
}
