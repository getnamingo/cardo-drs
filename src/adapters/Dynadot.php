<?php
namespace Namingo\Cardo\DRS\Adapters;

use Namingo\Cardo\DRS\Core\BaseAdapter;
use Namingo\Cardo\DRS\Core\Http;
use Namingo\Cardo\DRS\Core\DnsRecord;

class Dynadot extends BaseAdapter {
    protected string $brand='dynadot';
    private string $base;
    public function __construct(array $creds){ parent::__construct($creds); $this->base=$creds['base']??'https://api.dynadot.com/api3.json'; }
    private function request(string $command, array $params=[]): array {
        return Http::request('GET', $this->base, [
            'query' => array_merge([
                'key' => $this->creds['api_key'] ?? '',
                'command' => $command,
            ], $params),
        ]);
    }
    public function checkAvailability(array $domains): array {
        [$code,$body,$err]=$this->request('search',['domain'=>implode(',', $domains)]);
        $j=json_decode($body,true)?:[]; $available=[];$unavailable=[];$invalid=[];
        foreach(($j['search']??[]) as $row){ $d=strtolower($row['domain']); if(($row['status']??'')==='available')$available[]=$d; elseif(($row['status']??'')==='invalid')$invalid[]=$d; else $unavailable[]=$d; }
        return ['ok'=>$code<400&&!$err,'available'=>$available,'unavailable'=>$unavailable,'invalid'=>$invalid,'raw'=>$j,'http'=>$code,'err'=>$err];
    }
    public function registerDomain(string $domain, array $opts): array {
        [$code,$body,$err]=$this->request('register',['domain'=>$domain,'duration'=>$opts['years']??1,'privacy'=>!empty($opts['privacy'])?'1':'0']);
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function renewDomain(string $domain, int $years=1, array $opts=[]): array {
        [$code,$body,$err]=$this->request('renew',['domain'=>$domain,'duration'=>$years]);
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function transferDomain(string $domain, array $opts): array {
        [$code,$body,$err]=$this->request('transfer',['domain'=>$domain,'epp_code'=>$opts['auth_code']??'']);
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function getDomain(string $domain): array {
        [$code,$body,$err]=$this->request('get_domain_info',['domain'=>$domain]);
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function getDNS(string $domain): array {
        [$code,$body,$err]=$this->request('get_dns',['domain'=>$domain]);
        $j=json_decode($body,true)?:[];
        $out=$this->extractDnsRecords($j);
        $ok=$this->dynadotOk($j,$code,$err);
        return ['ok'=>$ok,'records'=>$out,'raw'=>$j,'http'=>$code,'err'=>$err!==''?$err:$this->dynadotError($j)];
    }
    public function setDNS(string $domain, array $records): array {
        $ok=true;$raw=[]; foreach($records as $r){ $raw[]=$this->addDNS($domain,$r); $ok=$ok && (end($raw)['ok']??false); } return ['ok'=>$ok,'raw'=>$raw];
    }
    public function addDNS(string $domain, array $record): array {
        [$code,$body,$err]=$this->request('set_dns',['domain'=>$domain,'record'=>json_encode([$record])]);
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function delDNS(string $domain, array $selector): array {
        if ($selector === []) {
            return ['ok'=>false,'raw'=>[],'http'=>null,'err'=>'Dynadot DNS delete selector is empty'];
        }

        $current=$this->getDNS($domain);
        if (empty($current['ok'])) {
            return $current;
        }

        $remaining=[];
        $deleted=0;
        foreach (($current['records']??[]) as $record) {
            if ($this->dnsMatches($record,$selector)) {
                $deleted++;
                continue;
            }
            $remaining[]=$record;
        }

        if ($deleted===0) {
            return [
                'ok'=>true,
                'deleted'=>0,
                'raw'=>$current['raw']??[],
                'http'=>$current['http']??null,
                'err'=>'',
            ];
        }

        $result=$this->replaceDnsRecords($domain,$remaining);
        $result['deleted']=!empty($result['ok'])?$deleted:0;
        return $result;
    }
    public function setNameServers(string $domain, array $nameservers): array {
        [$code,$body,$err]=$this->request('set_ns',['domain'=>$domain,'ns'=>implode(',', $nameservers)]);
        return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }
    public function raw(string $op, array $params=[]): array {
        [$code,$body,$err]=$this->request($op,$params); return ['ok'=>$code<400&&!$err,'raw'=>json_decode($body,true),'http'=>$code,'err'=>$err];
    }

    private function replaceDnsRecords(string $domain, array $records): array {
        if ($records===[]) {
            [$code,$body,$err]=$this->request('set_clear_domain_setting',['domain'=>$domain,'service'=>'dns']);
        } else {
            [$code,$body,$err]=$this->request('set_dns2',$this->buildDnsParams($domain,$records));
        }

        $j=json_decode($body,true)?:[];
        return [
            'ok'=>$this->dynadotOk($j,$code,$err),
            'raw'=>$j,
            'http'=>$code,
            'err'=>$err!==''?$err:$this->dynadotError($j),
        ];
    }

    private function buildDnsParams(string $domain, array $records): array {
        $params=['domain'=>$domain];
        $mainIndex=0;
        $subIndex=0;
        $ttl=null;

        foreach ($records as $record) {
            $type=strtolower((string)($record['type']??''));
            $host=(string)($record['host']??'@');
            $value=(string)($record['value']??'');
            $priority=$record['prio']??$record['priority']??null;

            if ($ttl===null && isset($record['ttl'])) {
                $ttl=(int)$record['ttl'];
            }

            if ($host==='' || $host==='@') {
                $i=$mainIndex++;
                $params["main_record_type{$i}"]=$type;
                $params["main_record{$i}"]=$value;
                if ($priority!==null) {
                    $params["main_recordx{$i}"]=(int)$priority;
                }
            } else {
                $i=$subIndex++;
                $params["subdomain{$i}"]=$host;
                $params["sub_record_type{$i}"]=$type;
                $params["sub_record{$i}"]=$value;
                if ($priority!==null) {
                    $params["sub_recordx{$i}"]=(int)$priority;
                }
            }
        }

        if ($ttl!==null && $ttl>0) {
            $params['ttl']=$ttl;
        }

        return $params;
    }

    private function extractDnsRecords(array $payload): array {
        if (isset($payload['records']) && is_array($payload['records'])) {
            $records=[];
            foreach ($payload['records'] as $r) {
                if (!is_array($r)) continue;
                $records[]=(new DnsRecord(
                    strtoupper((string)($r['type']??'')),
                    (string)($r['host']??'@'),
                    (string)($r['value']??''),
                    (int)($r['ttl']??3600),
                    isset($r['prio'])?(int)$r['prio']:(isset($r['priority'])?(int)$r['priority']:null)
                ))->toArray();
            }
            return $records;
        }

        $settings=$payload['GetDnsResponse']['GetDns']['NameServerSettings']
            ?? $payload['GetDnsResponse']['GetDnsContent']['NameServerSettings']
            ?? $payload['GetDnsResponse']['NameServerSettings']
            ?? [];

        $ttl=(int)($settings['TTL']??$settings['Ttl']??3600);
        return $this->collectDnsRecords($settings,$ttl>0?$ttl:3600);
    }

    private function collectDnsRecords(mixed $node, int $ttl): array {
        if (!is_array($node)) {
            return [];
        }

        $records=[];
        if (isset($node['RecordType']) && array_key_exists('Value',$node)) {
            $type=strtoupper((string)$node['RecordType']);
            $host=(string)($node['Subhost']??$node['SubHost']??$node['Host']??'@');
            if ($host==='') $host='@';
            $priority=null;
            if (isset($node['Value2']) && $node['Value2']!=='') {
                $priority=is_numeric($node['Value2'])?(int)$node['Value2']:null;
            } elseif (isset($node['Priority'])) {
                $priority=(int)$node['Priority'];
            }

            $records[]=(new DnsRecord(
                $type,
                $host,
                (string)$node['Value'],
                (int)($node['TTL']??$node['Ttl']??$ttl),
                $priority
            ))->toArray();
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                foreach ($this->collectDnsRecords($child,$ttl) as $record) {
                    $records[]=$record;
                }
            }
        }

        return $records;
    }

    private function dnsMatches(array $record, array $selector): bool {
        foreach (['type','host','value'] as $field) {
            if (!array_key_exists($field,$selector)) continue;
            $left=(string)($record[$field]??'');
            $right=(string)$selector[$field];
            if ($field==='type') {
                $left=strtoupper($left);
                $right=strtoupper($right);
            }
            if ($left!==$right) return false;
        }

        $priority=$selector['prio']??$selector['priority']??null;
        if ($priority!==null && (int)($record['prio']??0)!==(int)$priority) {
            return false;
        }

        return true;
    }

    private function dynadotOk(array $payload, int $http, string $err): bool {
        if ($http>=400 || $err!=='') {
            return false;
        }

        $response=reset($payload);
        if (!is_array($response)) {
            return true;
        }

        if (isset($response['ResponseCode']) && (int)$response['ResponseCode']!==0) {
            return false;
        }
        if (isset($response['SuccessCode']) && (int)$response['SuccessCode']!==0) {
            return false;
        }
        if (isset($response['Status']) && strtolower((string)$response['Status'])==='error') {
            return false;
        }

        return true;
    }

    private function dynadotError(array $payload): string {
        $response=reset($payload);
        return is_array($response) ? (string)($response['Error']??'') : '';
    }
}
