<?php
declare(strict_types=1);
/** Public-key verified, device-bound online activation. Never uploads workspace records. */
final class LicenseService {
    public static function config(): array {
        $file=__DIR__.'/license-config.json';
        $value=is_file($file)?json_decode(file_get_contents($file),true,8,JSON_THROW_ON_ERROR):[];
        $value+=['required'=>false,'publicKey'=>'','server'=>'https://orean.tech'];
        if(defined('DATA')){
            $marker=self::directory().'/commercial';
            if($value['required']&&!is_file($marker))file_put_contents($marker,'1',LOCK_EX);
            if(is_file($marker))$value['required']=true;
        }
        return $value;
    }
    public static function directory(): string {
        $root=class_exists('ProfileManager')?ProfileManager::directory('default'):DATA;
        $dir=$root.'/account';if(!is_dir($dir))mkdir($dir,0700,true);return $dir;
    }
    public static function device(): string {
        $native=getenv('LIFEOS_DEVICE_HASH')?:'';
        if(preg_match('/^[a-f0-9]{64}$/D',$native))return $native;
        return hash('sha256','lifeos-development-device:'.php_uname('n'));
    }
    private static function read(): array {
        $path=self::directory().'/activation.json';
        if(!is_file($path)||filesize($path)>20000)return [];
        $data=json_decode(file_get_contents($path),true,16);return is_array($data)?$data:[];
    }
    private static function save(array $data): void {
        $file=self::directory().'/activation.json';$temp=$file.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($temp,json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX);chmod($temp,0600);
        if(!rename($temp,$file)){@unlink($temp);throw new RuntimeException('Could not save activation.');}
    }
    private static function secret(?string $value=null): string {
        $file=self::directory().'/.token-key';
        if(!is_file($file)){$h=@fopen($file,'x+b');if($h){chmod($file,0600);fwrite($h,random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));fclose($h);}}
        $key=file_get_contents($file);if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new RuntimeException('Account protection key unavailable.');
        if($value!==null){$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);return base64_encode($nonce.sodium_crypto_secretbox($value,$nonce,$key));}
        $raw=base64_decode((string)(self::read()['token']??''),true);if(!$raw||strlen($raw)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES)return '';
        $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$key);return $plain===false?'':$plain;
    }
    private static function decode(string $value): string {
        if(!preg_match('/^[A-Za-z0-9_-]+$/D',$value))throw new InvalidArgumentException('Invalid activation signature.');
        $decoded=base64_decode(strtr($value,'-_','+/'),true);if($decoded===false)throw new InvalidArgumentException('Invalid activation signature.');return $decoded;
    }
    public static function verify(string $lease,?int $clock=null): array {
        $clock??=time();$parts=explode('.',$lease);$key=base64_decode(self::config()['publicKey'],true);
        if(count($parts)!==2||!is_string($key)||strlen($key)!==32)throw new InvalidArgumentException('Activation is not configured for this build.');
        $signature=self::decode($parts[1]);
        if(strlen($signature)!==64||!sodium_crypto_sign_verify_detached($signature,$parts[0],$key))throw new InvalidArgumentException('This activation could not be verified.');
        $claims=json_decode(self::decode($parts[0]),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($claims)||($claims['v']??null)!==1||($claims['iss']??'')!=='https://orean.tech'||($claims['aud']??'')!=='lifeos-desktop'||!hash_equals(self::device(),(string)($claims['device']??''))||!is_int($claims['iat']??null)||!is_int($claims['exp']??null)||$claims['iat']>$clock+300||$claims['exp']<=$clock||$claims['exp']-$claims['iat']>7*86400||!is_array($claims['permissions']??null)||!is_string($claims['sub']??null))throw new InvalidArgumentException('This activation expired or belongs to another computer. Connect and verify your license.');
        return $claims;
    }
    public static function state(): array {
        $data=self::read();$claims=null;
        try{if(isset($data['lease']))$claims=self::verify($data['lease'],max(time(),(int)($data['last_clock']??0)));}catch(Throwable $e){}
        return ['signedIn'=>!empty($data['token']),'user'=>$data['user']??null,'claims'=>$claims,'required'=>(bool)self::config()['required'],'error'=>$data['error']??''];
    }
    private static function request(string $action,array $value=[],bool $authenticated=false): array {
        $server=self::config()['server'];
        if($server!=='https://orean.tech')throw new InvalidArgumentException('The LifeOS account server is not configured correctly.');
        $ch=curl_init($server.'/api/lifeos/account/'.$action);
        $headers=['Content-Type: application/json','Accept: application/json'];
        if($authenticated){$token=self::secret();if($token==='')throw new InvalidArgumentException('Sign in to your LifeOS account first.');$headers[]='Authorization: Bearer '.$token;}
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($value+['client'=>'desktop'],JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_MAXFILESIZE=>32000]);
        $result=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if(!is_string($result)||strlen($result)>32000||$status>=500||$status===0)throw new InvalidArgumentException('The account service is unavailable. Check your connection and try again.');
        $body=json_decode($result,true,16);
        if(!is_array($body))throw new InvalidArgumentException('The account service returned an unreadable response.');
        if($status<200||$status>=300){
            if($authenticated&&in_array($status,[401,403],true)){$data=self::read();unset($data['lease']);if($status===401)unset($data['token']);$data['error']='Sign in and verify your license to continue.';self::save($data);}
            throw new InvalidArgumentException(is_string($body['error']??null)?mb_substr($body['error'],0,250):'Could not complete this account request.');
        }
        return $body;
    }
    public static function register(array $input): void { self::request('register',['email'=>$input['email']??'','name'=>$input['name']??'','password'=>$input['password']??'']); }
    public static function login(array $input): void {
        $body=self::request('login',['email'=>$input['email']??'','password'=>$input['password']??'']);
        if(!is_string($body['token']??null)||!preg_match('/^[A-Za-z0-9_-]{43}$/D',$body['token'])||!is_array($body['user']??null))throw new InvalidArgumentException('The account service returned an invalid session.');
        $old=self::read();$same=($old['user']['id']??null)===($body['user']['id']??null);
        self::save(['user'=>$body['user'],'token'=>self::secret($body['token'])]+($same?array_intersect_key($old,array_flip(['lease','refresh_after','last_clock'])):[]));
    }
    private static function accept(array $body): void {
        $claims=self::verify((string)($body['lease']??''));$data=self::read();
        if($claims['sub']!==($data['user']['id']??''))throw new InvalidArgumentException('This activation belongs to a different account.');
        $data['lease']=$body['lease'];$data['refresh_after']=min(time()+12*3600,(int)($body['refreshAfter']??0));$data['last_clock']=time();$data['error']='';self::save($data);
        file_put_contents(self::directory().'/commercial','1',LOCK_EX);
    }
    private static function deviceInput(): array { return ['deviceHash'=>self::device(),'deviceName'=>mb_substr(php_uname('n'),0,100)]; }
    public static function activate(string $key): void { self::accept(self::request('activate',self::deviceInput()+['key'=>$key],true)); }
    public static function refresh(): void {
        $data=self::read();$claims=null;try{$claims=self::verify((string)($data['lease']??''),(int)($data['last_clock']??time()));}catch(Throwable $e){}
        // Expired leases retain their signed activation id for online renewal.
        if(!$claims&&isset($data['lease'])){try{$parts=explode('.',$data['lease']);$claims=self::verify($data['lease'],(int)(json_decode(self::decode($parts[0]),true)['iat']??0));}catch(Throwable $e){}}
        if(!$claims)throw new InvalidArgumentException('Enter your license key to activate this computer.');
        self::accept(self::request('refresh',self::deviceInput()+['activation'=>$claims['activation']],true));
    }
    public static function logout(): void { try{self::request('logout',[],true);}catch(Throwable $e){}self::save([]); }
    public static function tick(): void {
        $data=self::read();if(empty($data['token'])||empty($data['lease'])||time()<(int)($data['refresh_after']??0))return;
        $data['refresh_after']=time()+300;$data['last_clock']=max(time(),(int)($data['last_clock']??0));self::save($data);
        try{self::refresh();}catch(InvalidArgumentException $e){$data=self::read();$data['error']=$e->getMessage();self::save($data);}
    }
    public static function allowed(string $permission): bool {
        $state=self::state();if(!$state['required']&&!$state['signedIn'])return true;
        return $state['claims']!==null&&in_array($permission,$state['claims']['permissions'],true);
    }
    public static function permission(string $path): ?string {
        foreach(['/settings/accounts','/settings/categories','/settings/budgets','/settings/rules','/settings/grades']as $prefix)if($path===$prefix||str_starts_with($path,$prefix.'/'))return $prefix==='/settings/grades'?'study':'money';
        $groups=['ai'=>['assistant','intelligence'],'planner'=>['planner','growth','focus','freedom','lessons','tasks','review','life','life-admin','desktop','insights'],'money'=>['salary','expenses','investments','goals'],'work'=>['work','roles','employee','career','small-business'],'study'=>['uni','study'],'documents'=>['documents'],'connections'=>['connectivity','websites','web-admin','automation','api']];
        $prefix=explode('/',trim($path,'/'))[0];foreach($groups as $permission=>$prefixes)if(in_array($prefix,$prefixes,true))return $permission;return null;
    }
    public static function guard(Router $router): void {
        $router->before(function(string $method,string $path){
            if(str_starts_with($path,'/account')||in_array($path,['/unlock','/unlock/hello','/lock','/idle-lock','/profiles','/profiles/switch','/session/activity'],true))return;
            self::tick();$state=self::state();
            if($state['required']&&$state['claims']===null){if($method==='GET'){echo redirect('/account');}else{http_response_code(403);echo 'Activate your LifeOS license from Account & activation.';}exit;}
            $permission=self::permission($path);
            if($permission!==null&&!self::allowed($permission)){http_response_code(403);echo View::render(ROOT.'/app/Core/views/error.php',['title'=>'Access not included','message'=>'Your LifeOS account does not include this feature. Contact Orean to review your access.','back'=>'/account'],'lock');exit;}
        });
    }
    public static function routes(Router $router): void {
        $page=fn(array $extra=[])=>View::render(ROOT.'/app/Core/views/account.php',$extra+['title'=>'Account & activation','account'=>self::state()],'lock');
        $router->get('/account',fn()=>$page());
        $router->post('/account/{action}',function(array $params)use($page){
            try{
                switch($params['action']){
                    case 'register':self::register($_POST);$message='Account created. Sign in to activate LifeOS.';break;
                    case 'login':self::login($_POST);$message='Signed in. Enter your license key to activate this computer.';break;
                    case 'activate':self::activate(text_input('key',80));$message='LifeOS is activated for this computer.';break;
                    case 'refresh':self::refresh();$message='Your license has been verified.';break;
                    case 'logout':self::logout();$message='Signed out of your LifeOS account.';break;
                    default:throw new InvalidArgumentException('Unsupported account action.');
                }
                return $page(['message'=>$message]);
            }catch(InvalidArgumentException $e){http_response_code(422);return $page(['error'=>$e->getMessage()]);}
        });
    }
}
