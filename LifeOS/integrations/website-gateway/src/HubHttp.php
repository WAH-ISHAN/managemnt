<?php
declare(strict_types=1);
/** Bounded outbound traffic. Every DNS answer must be public; pinned TLS and no redirects. */
final class LifeOSHubHttp {
    private static $transport=null;
    public static function testTransport(?callable $transport):void {if(PHP_SAPI!=='cli')throw new LogicException('CLI tests only.');self::$transport=$transport;}
    public static function send(string $url,string $method='GET',string|array|null $body=null,array $headers=[],int $limit=1048576):array {
        $url=WebsiteHttpClient::normalizeUrl($url);
        if(self::$transport!==null)return (self::$transport)($url,$method,$body,$headers);
        $host=parse_url($url,PHP_URL_HOST);$addresses=[];
        if(filter_var(trim($host,'[]'),FILTER_VALIDATE_IP))$addresses=[trim($host,'[]')];
        else foreach(dns_get_record($host,DNS_A|DNS_AAAA) ?: [] as $row)if(isset($row['ip'])||isset($row['ipv6']))$addresses[]=$row['ip'] ?? $row['ipv6'];
        if(!$addresses)throw new RuntimeException('Host unavailable.');foreach($addresses as $ip)if(!WebsiteHttpClient::isPublicIp($ip))throw new RuntimeException('Public HTTPS hosts only.');
        $ip=$addresses[0];$response='';$tooLarge=false;$curl=curl_init($url);$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_CERTINFO=>true,CURLOPT_USERAGENT=>'LifeOS-Hub/2',CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$response,&$tooLarge,$limit){if(strlen($response)+strlen($chunk)>$limit){$tooLarge=true;return 0;}$response.=$chunk;return strlen($chunk);}];
        if($body!==null)$options[CURLOPT_POSTFIELDS]=$body;if(!filter_var(trim($host,'[]'),FILTER_VALIDATE_IP))$options[CURLOPT_RESOLVE]=[$host.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip)];if(PHP_OS_FAMILY==='Windows' && defined('CURLSSLOPT_NATIVE_CA'))$options[CURLOPT_SSL_OPTIONS]=CURLSSLOPT_NATIVE_CA;
        curl_setopt_array($curl,$options);$start=microtime(true);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$contentType=(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE);$certs=curl_getinfo($curl,CURLINFO_CERTINFO);$expires=$certs[0]['Expire date'] ?? null;curl_close($curl);
        if($tooLarge)throw new RuntimeException('Response exceeded 1 MB.');if($ok===false)throw new RuntimeException('Secure HTTP request failed.');
        return ['status'=>$status,'body'=>$response,'content_type'=>$contentType,'latency_ms'=>(int)round((microtime(true)-$start)*1000),'ssl_expires'=>is_string($expires)&&strtotime($expires)!==false?gmdate('c',strtotime($expires)):null];
    }
}
