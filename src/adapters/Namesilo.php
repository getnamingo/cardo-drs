<?php
namespace Namingo\Cardo\DRS\Adapters;

use Namingo\Cardo\DRS\Core\BaseAdapter;
use Namingo\Cardo\DRS\Core\Http;
use Namingo\Cardo\DRS\Core\DnsRecord;

class Namesilo extends BaseAdapter {
    protected string $brand='namesilo';
    private string $base = 'https://www.namesilo.com/api';

    private function request(string $op, array $params=[]): array {
        return Http::request('GET', $this->base . '/' . $op, [
            'query' => array_merge([
                'version' => '1',
                'type' => 'json',
                'key' => $this->creds['api_key'] ?? '',
            ], $params),
        ]);
    }
    public function checkAvailability(array $domains): array {
        [$code,$body,$err] = $this->request('checkRegisterAvailability', ['domains'=>implode(',', $domains)]);
        $j = $this->json($body);
        $r = $j['reply'] ?? [];
        $toArr = fn($v)=>is_array($v)?array_values($v):($v?[$v]:[]);
        $available   = $toArr($r['available']['domain']   ?? $r['available']   ?? []);
        $unavailable = $toArr($r['unavailable']['domain'] ?? $r['unavailable'] ?? []);
        $invalid     = $toArr($r['invalid']['domain']     ?? $r['invalid']     ?? []);
        return ['ok'=>$code<400 && !$err, 'available'=>$available,'unavailable'=>$unavailable,'invalid'=>$invalid, 'raw'=>$j, 'http'=>$code, 'err'=>$err];
    }
    public function registerDomain(string $domain, array $opts): array {
        $params = [
            'domain'=>$domain,'years'=>$opts['years'] ?? 1,
            'private'=>($opts['privacy'] ?? true) ? '1':'0',
            'auto_renew'=>($opts['auto_renew'] ?? false) ? '1':'0',
        ];
        if (!empty($opts['coupon'])) $params['coupon'] = $opts['coupon'];
        foreach (['fn'=>'first_name','ln'=>'last_name','ad'=>'address','cy'=>'city','st'=>'state','zp'=>'zip','ct'=>'country','em'=>'email','ph'=>'phone'] as $short=>$key) {
            if (isset($opts['registrant'][$key])) $params["rr_$short"] = $opts['registrant'][$key];
        }
        [$code,$body,$err] = $this->request('registerDomain', $params);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }
    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        [$code,$body,$err] = $this->request('renewDomain', ['domain'=>$domain,'years'=>$years]);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }
    public function transferDomain(string $domain, array $opts): array {
        [$code,$body,$err] = $this->request('transferDomain', ['domain'=>$domain,'auth'=>$opts['auth_code'] ?? '']);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }
    public function getDomain(string $domain): array {
        [$code,$body,$err] = $this->request('getDomainInfo', ['domain'=>$domain]);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }
    public function getDNS(string $domain): array {
        [$code,$body,$err] = $this->request('dnsListRecords', ['domain'=>$domain]);
        $j = $this->json($body);
        $recs=[];
        foreach (($j['reply']['resource_record'] ?? []) as $r) {
            $recs[] = (new DnsRecord($r['type'],$r['host'],$r['value'], (int)($r['ttl']??3600), isset($r['distance'])?(int)$r['distance']:null))->toArray();
        }
        return ['ok'=>$code<400 && !$err, 'records'=>$recs, 'raw'=>$j, 'http'=>$code, 'err'=>$err];
    }
    public function setDNS(string $domain, array $records): array {
        $cur = $this->getDNS($domain);
        if (!empty($cur['records'])) {
            foreach ($cur['records'] as $r) { $this->delDNS($domain, ['record_id'=>$r['record_id'] ?? null]); }
        }
        $ok=true; $raw=[];
        foreach ($records as $r) { $res=$this->addDNS($domain, $r); $ok=$ok && !empty($res['ok']); $raw[]=$res; }
        return ['ok'=>$ok,'raw'=>$raw];
    }
    public function addDNS(string $domain, array $record): array {
        $q = ['domain'=>$domain,'rrtype'=>$record['type'],'rrhost'=>$record['host'],'rrvalue'=>$record['value'],'rrttl'=>$record['ttl'] ?? 3600];
        if (isset($record['prio'])) $q['rrdistance']=(int)$record['prio'];
        [$code,$body,$err] = $this->request('dnsAddRecord', $q);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }
    public function delDNS(string $domain, array $selector): array {
        [$code,$body,$err] = $this->request('dnsDeleteRecord', ['domain'=>$domain,'rrid'=>$selector['record_id'] ?? '']);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }
    public function setNameServers(string $domain, array $nameservers): array {
        [$code,$body,$err] = $this->request('changeNameServers', ['domain'=>$domain,'ns'=>implode(',', $nameservers)]);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err];
    }
    public function raw(string $op, array $params=[]): array {
        [$code,$body,$err] = $this->request($op, $params);
        return ['ok'=>$code<400 && !$err, 'raw'=>$this->json($body), 'http'=>$code, 'err'=>$err, 'endpoint'=>$this->base . '/' . $op];
    }
}
