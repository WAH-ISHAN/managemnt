<?php
declare(strict_types=1);

/** A bounded, HTTPS-only connector client. No redirects, ambient proxy, or private-network targets. */
final class WebsiteHttpClient {
    public const MAX_RESPONSE=1048576;
    public const MAX_REQUEST=262144;
    private static $testTransport=null;
    private static $testResolver=null;

    /** Test adapters are code-only and unavailable to web requests. */
    public static function setTestTransport(?callable $transport,?callable $resolver=null): void {
        if (PHP_SAPI!=='cli') throw new LogicException('Test adapters are unavailable in the web server.');
        self::$testTransport=$transport; self::$testResolver=$resolver;
    }

    public static function normalizeUrl(string $value,bool $optional=false): string {
        $value=trim($value);
        if ($value==='' && $optional) return '';
        if ($value==='' || strlen($value)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$value)) throw new InvalidArgumentException('Enter a valid public HTTPS URL without credentials or spaces.');
        if (!str_contains($value,'://')) $value='https://'.$value;
        $parts=parse_url($value);
        if (!$parts || strtolower($parts['scheme'] ?? '')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port']!==443)) throw new InvalidArgumentException('Use an HTTPS URL on port 443, without a query, fragment, or embedded credentials.');
        $host=strtolower($parts['host']); $ip=trim($host,'[]');
        if (filter_var($ip,FILTER_VALIDATE_IP)) {
            if (!self::isPublicIp($ip)) throw new InvalidArgumentException('Connector URLs must use a public address. Local and private networks are not supported.');
        } elseif (strlen($host)>253 || !str_contains($host,'.') || str_ends_with($host,'.') || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$host) || preg_match('/\.(?:localhost|local|internal|onion)$/D',$host)) {
            throw new InvalidArgumentException('Enter a public domain name or public IP address. Use an ASCII/punycode domain name.');
        }
        $path=$parts['path'] ?? '';
        return 'https://'.$host.$path;
    }

    public static function isPublicIp(string $ip): bool {
        if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        $packed=inet_pton($ip);
        if ($packed===false) return false;
        if (strlen($packed)===4) {
            foreach (['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $range) if (self::inRange($packed,$range)) return false;
            return true;
        }
        if (!self::inRange($packed,'2000::/3')) return false;
        foreach (['2001::/23','2001:db8::/32','2002::/16','3fff::/20'] as $range) if (self::inRange($packed,$range)) return false;
        return true;
    }

    private static function inRange(string $ip,string $range): bool {
        [$network,$bits]=explode('/',$range); $base=inet_pton($network);
        if ($base===false || strlen($base)!==strlen($ip)) return false;
        $bytes=intdiv((int)$bits,8); $remaining=(int)$bits%8;
        if (substr($ip,0,$bytes)!==substr($base,0,$bytes)) return false;
        return $remaining===0 || (ord($ip[$bytes]) & (0xff<<(8-$remaining)))===(ord($base[$bytes]) & (0xff<<(8-$remaining)));
    }

    private static function addresses(string $host): array {
        $ip=trim($host,'[]');
        if (filter_var($ip,FILTER_VALIDATE_IP)) return [$ip];
        if (self::$testResolver!==null) $addresses=(self::$testResolver)($host);
        else {
            $records=@dns_get_record($host,DNS_A | DNS_AAAA);
            $addresses=[];
            foreach ($records ?: [] as $row) if (isset($row['ip']) || isset($row['ipv6'])) $addresses[]=$row['ip'] ?? $row['ipv6'];
        }
        if (!is_array($addresses) || !$addresses || count($addresses)>64) throw new InvalidArgumentException('The connector domain could not be resolved to a public address.');
        foreach ($addresses as $address) if (!is_string($address) || !self::isPublicIp($address)) throw new InvalidArgumentException('The connector domain resolves to a local, private, or reserved address.');
        return array_values(array_unique($addresses));
    }

    public static function request(string $url,?string $token,array $payload): array {
        $url=self::normalizeUrl($url);
        if ($token===null && ($payload['action'] ?? '')!=='login') throw new InvalidArgumentException('Sign in to the website first.');
        if ($token!==null && !preg_match('/^[\x21-\x7e]{32,4096}$/D',$token)) throw new InvalidArgumentException('Enter a connector token of 32 to 4096 visible characters, without spaces.');
        try { $json=json_encode($payload,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }
        catch (JsonException) { throw new InvalidArgumentException('The connector request contains invalid text or unsupported data.'); }
        if (strlen($json)>self::MAX_REQUEST) throw new InvalidArgumentException('This record is too large for the connector. Shorten the content and try again.');
        $parts=parse_url($url); $host=$parts['host'];
        $addresses=self::addresses($host); $address=$addresses[0];
        $pin=$host.':443:'.(str_contains($address,':') ? '['.$address.']' : $address);
        $options=['addresses'=>$addresses,'pinnedAddress'=>$address,'verifyPeer'=>true,'verifyHost'=>2,'followRedirects'=>false,'proxy'=>'','maxResponse'=>self::MAX_RESPONSE];
        if (self::$testTransport!==null) {
            $response=(self::$testTransport)($url,$token,$payload,$options);
            if (!is_array($response) || !isset($response['status'],$response['body']) || !is_int($response['status']) || !is_string($response['body'])) throw new RuntimeException('Invalid test transport response.');
            $status=$response['status']; $body=$response['body']; $contentType=$response['content_type'] ?? 'application/json';
            if (strlen($body)>self::MAX_RESPONSE) throw new InvalidArgumentException('The connector response is too large. Use smaller pages or shorter records.');
        } else {
            if (!extension_loaded('curl')) throw new InvalidArgumentException('Website connections need the bundled cURL runtime. Update or reopen the desktop app.');
            $curl=curl_init($url); $body=''; $contentType=''; $headerBytes=0; $tooLarge=false;
            $settings=[
                CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,
                CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json','Accept: application/json','Expect:'],$token!==null ? ['Authorization: Bearer '.$token] : []),
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROXY=>'',CURLOPT_NOPROXY=>'*',CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
                CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'LifeOS-WebsiteManager/1',CURLOPT_FRESH_CONNECT=>true,
                CURLOPT_HEADERFUNCTION=>static function ($handle,string $line) use (&$headerBytes,&$contentType,&$tooLarge): int {
                    $headerBytes+=strlen($line);
                    if ($headerBytes>32768) { $tooLarge=true; return 0; }
                    if (stripos($line,'Content-Type:')===0) $contentType=trim(substr($line,13));
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use (&$body,&$tooLarge): int {
                    if (strlen($body)+strlen($chunk)>self::MAX_RESPONSE) { $tooLarge=true; return 0; }
                    $body.=$chunk; return strlen($chunk);
                }
            ];
            if (PHP_OS_FAMILY==='Windows' && defined('CURLSSLOPT_NATIVE_CA')) $settings[CURLOPT_SSL_OPTIONS]=CURLSSLOPT_NATIVE_CA;
            if (!filter_var(trim($host,'[]'),FILTER_VALIDATE_IP)) $settings[CURLOPT_RESOLVE]=[$pin];
            curl_setopt_array($curl,$settings);
            $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
            if ($tooLarge) throw new InvalidArgumentException('The connector response is too large. Use smaller pages or shorter records.');
            if ($ok===false) throw new InvalidArgumentException('The HTTPS connector could not be reached securely. Check its address, certificate and network connection.');
        }
        if ($status===401 || $status===403) throw new InvalidArgumentException(($payload['action'] ?? '')==='login' ? 'Website username or password is incorrect, or access is disabled.' : 'The website did not authorise this request. Sign in again or check your permissions.');
        if ($status===429) throw new InvalidArgumentException('Too many website login attempts. Try again in 15 minutes.');
        if ($status===409) throw new InvalidArgumentException('This record changed on the website. Refresh the records before trying again.');
        if ($status>=300 && $status<400) throw new InvalidArgumentException('The connector redirected the request. Enter its final HTTPS endpoint.');
        if ($status<200 || $status>=300) throw new InvalidArgumentException('The connector could not complete this operation (HTTP '.$status.').');
        if (!is_string($contentType) || !preg_match('~^application/(?:[a-z0-9.+-]+\+)?json(?:\s*;|$)~i',$contentType)) throw new InvalidArgumentException('The endpoint did not return connector JSON. Check the connector URL.');
        try { $result=json_decode($body,true,24,JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new InvalidArgumentException('The connector returned invalid JSON.'); }
        if (!is_array($result) || array_is_list($result) || ($result['protocol'] ?? '')!=='lifeos-admin-v1') throw new InvalidArgumentException('This endpoint does not support the LifeOS admin connector protocol.');
        return $result;
    }
}
