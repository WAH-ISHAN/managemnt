<?php
declare(strict_types=1);
/** PHP/Laravel server-side connector. No dependency on a website framework. */
final class LifeOSContentConnector {
    public function __construct(private string $gateway,private string $site,private string $token,private string $webhookSecret){
        $url=parse_url($gateway);if(!$url||($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])||!preg_match('/^site_[a-f0-9]{16}$/D',$site)||!preg_match('/^[\x21-\x7e]{32,4096}$/D',$token))throw new InvalidArgumentException('Configure the HTTPS gateway and server-only site credentials.');$this->gateway=rtrim($gateway,'/');
    }
    private function request(string $path,string $method='GET',?array $body=null):array{
        $curl=curl_init($this->gateway.'/api/v1/sites/'.$this->site.$path);$headers=['Accept: application/json','Authorization: Bearer '.$this->token];if($body!==null)$headers[]='Content-Type: application/json';$raw='';$tooLarge=false;
        $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$raw,&$tooLarge){if(strlen($raw)+strlen($chunk)>1048576){$tooLarge=true;return 0;}$raw.=$chunk;return strlen($chunk);}];if($body!==null)$options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR);if(PHP_OS_FAMILY==='Windows'&&defined('CURLSSLOPT_NATIVE_CA'))$options[CURLOPT_SSL_OPTIONS]=CURLSSLOPT_NATIVE_CA;
        curl_setopt_array($curl,$options);$ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);if($ok===false||$tooLarge||$status<200||$status>=300)throw new RuntimeException('LifeOS content request failed.');$data=json_decode($raw,true,48,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Invalid LifeOS response.');return $data;
    }
    public function register(array $manifest,array $initial=[]):array{return $this->request('/register','POST',['manifest'=>$manifest,'initial'=>$initial]);}
    public function manifest():array{return $this->request('/manifest');}
    public function page(string $slug):array{if(!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$slug))throw new InvalidArgumentException('Invalid page slug.');return $this->request('/pages/'.$slug);}
    public function verifyWebhook(string $raw,array $headers,?int $now=null):array{
        $now??=time();if(strlen($raw)>65536||strlen($this->webhookSecret)<32)throw new InvalidArgumentException('Invalid webhook configuration or size.');$headers=array_change_key_case($headers,CASE_LOWER);$timestamp=$headers['x-lifeos-timestamp']??'';$signature=$headers['x-lifeos-signature']??'';
        if(!is_string($timestamp)||!preg_match('/^\d{10,12}$/D',$timestamp)||abs($now-(int)$timestamp)>300||(int)$timestamp>$now+30||!is_string($signature)||!preg_match('/^sha256=[a-f0-9]{64}$/D',$signature)||!hash_equals('sha256='.hash_hmac('sha256',$timestamp.'.'.$raw,$this->webhookSecret),$signature))throw new InvalidArgumentException('Webhook authentication failed.');
        $event=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($event)||($event['event']??'')!=='content.published'||($event['site_id']??'')!==$this->site||!is_string($event['page']??null)||!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$event['page'])||!is_int($event['version']??null)||$event['version']<0||!is_string($event['deployment_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$event['deployment_id']))throw new InvalidArgumentException('Invalid webhook event.');
        $result=$this->request('/webhooks/claim','POST',['deployment_id'=>$event['deployment_id']]);return [...$event,'duplicate'=>!$result['claimed']];
    }
    public function releaseWebhook(array $event):array{return $this->request('/webhooks/release','POST',['deployment_id'=>$event['deployment_id']]);}
    public function reportDeployment(array $event,string $status):array{if(!preg_match('/^[a-f0-9]{32}$/D',$event['deployment_id']??'')||!in_array($status,['building','live','failed'],true))throw new InvalidArgumentException('Invalid deployment acknowledgement.');return $this->request('/deployments/'.$event['deployment_id'].'/status','POST',['status'=>$status]);}
    public static function versionMarker(array $page):string{if(!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$page['slug']??'')||!is_int($page['version']??null)||$page['version']<0)throw new InvalidArgumentException('Invalid page version.');return 'lifeos-version:'.$page['slug'].':'.$page['version'];}
    public static function escape(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
