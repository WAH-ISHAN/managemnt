<?php
declare(strict_types=1);
final class HubClientService {
    private const SESSION='lifeos_cloud_hub';
    private static function key():string{$key=base64_decode(getenv('LIFEOS_WEBSITE_KEY') ?: '',true);if($key===false||strlen($key)!==32)throw new InvalidArgumentException('Open the updated LifeOS desktop app to connect to your cloud Hub.');return $key;}
    private static function store(array $value):void{$iv=random_bytes(12);$tag='';$encrypted=openssl_encrypt(json_encode($value,JSON_THROW_ON_ERROR),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,'lifeos-cloud-hub');if($encrypted===false)throw new RuntimeException('Cannot protect Hub session.');$_SESSION[self::SESSION]=base64_encode($iv.$tag.$encrypted);}
    public static function disconnect():void{unset($_SESSION[self::SESSION],$_SESSION['hub_one_time']);}
    private static function session():?array{
        if(!isset($_SESSION[self::SESSION]))return null;
        try{$raw=base64_decode($_SESSION[self::SESSION],true);if($raw===false||strlen($raw)<29)throw new RuntimeException();$json=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'lifeos-cloud-hub');if($json===false)throw new RuntimeException();$session=json_decode($json,true,16,JSON_THROW_ON_ERROR);if(($session['expires_at']??0)<=time())throw new RuntimeException();return $session;}catch(Throwable){self::disconnect();return null;}
    }
    public static function info():?array{$session=self::session();return $session?array_intersect_key($session,array_flip(['base','role','actor','expires_at'])):null;}
    private static function call(string $base,string $token,string $method,string $path,array $input=[]):array{
        $headers=['Accept: application/json','Authorization: Bearer '.$token];$body=null;if(in_array($method,['POST','PUT'],true)){$headers[]='Content-Type: application/json';$body=json_encode($input,JSON_THROW_ON_ERROR);}
        try{$response=WebsiteHubTransport::send($base.$path,$method,$body,$headers);}catch(Throwable){throw new InvalidArgumentException('The cloud Hub could not be reached securely. Check its public HTTPS address and server status.');}
        try{$data=json_decode($response['body'],true,64,JSON_THROW_ON_ERROR);}catch(JsonException){throw new InvalidArgumentException('The Hub did not return valid JSON.');}
        if($response['status']<200||$response['status']>=300)throw new InvalidArgumentException(is_string($data['error']??null)?mb_substr($data['error'],0,500):'The cloud Hub rejected this operation.');
        if(!is_array($data))throw new InvalidArgumentException('Invalid cloud Hub response.');return $data;
    }
    public static function login(string $base,string $username,string $password,string $operator=''):void{
        $base=rtrim(WebsiteHttpClient::normalizeUrl($base),'/');self::key();self::disconnect();$token=$operator;$expires=time()+1800;
        if($operator===''){
            $headers=['Content-Type: application/json','Accept: application/json'];
            try{$r=WebsiteHubTransport::send($base.'/api/v1/login','POST',json_encode(['username'=>$username,'password'=>$password],JSON_THROW_ON_ERROR),$headers);$data=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);}catch(Throwable){throw new InvalidArgumentException('The cloud Hub sign-in could not be reached securely.');}
            if($r['status']!==200||!is_string($data['token']??null)||!is_int($data['expires_at']??null)||$data['expires_at']<=time())throw new InvalidArgumentException('Hub username or password is incorrect, unavailable, or temporarily rate limited.');$token=$data['token'];$expires=min($expires,$data['expires_at']);
        }
        if(!preg_match('/^[\x21-\x7e]{32,4096}$/D',$token))throw new InvalidArgumentException('Enter a valid provider token or client account.');
        $overview=self::call($base,$token,'GET','/api/v1/overview');if(!in_array($overview['role']??'',['provider','client'],true)||!is_array($overview['sites']??null))throw new InvalidArgumentException('The endpoint is not a LifeOS cloud Hub.');
        self::store(['base'=>$base,'token'=>$token,'expires_at'=>$expires,'role'=>$overview['role'],'actor'=>$overview['actor']]);Db::set('hub_gateway_url',$base);
    }
    public static function request(string $method,string $path,array $input=[]):array{$session=self::session()??throw new InvalidArgumentException('Sign in to your cloud Hub again.');if(!str_starts_with($path,'/api/v1/')||str_contains($path,'..')||str_contains($path,'?')||str_contains($path,'#'))throw new InvalidArgumentException('Invalid Hub resource.');return self::call($session['base'],$session['token'],$method,$path,$input);}
    public static function upload(string $site,array $file):array{
        $session=self::session()??throw new InvalidArgumentException('Sign in to the Hub again.');
        if(!preg_match('/^site_[a-f0-9]{16}$/D',$site)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Choose a valid media upload.');$info=WebAdminService::inspectMedia($file['tmp_name']);
        $r=WebsiteHubTransport::send($session['base'].'/api/v1/sites/'.$site.'/media','POST',['file'=>new CURLFile($file['tmp_name'],$info['mime'],basename($file['name']))],['Accept: application/json','Authorization: Bearer '.$session['token']]);$data=json_decode($r['body'],true,32,JSON_THROW_ON_ERROR);if($r['status']!==200)throw new InvalidArgumentException($data['error']??'Cloud upload failed.');return $data;
    }
}
