<?php
declare(strict_types=1);

/** Local connection profiles plus schema-driven, explicitly requested remote operations. */
final class WebsiteService {
    private const TTL=1800;
    private const SESSION_KEY='website_sessions';
    public const FIELD_TYPES=['text','textarea','number','boolean','date'];

    public static function profiles(): array { return Db::all('SELECT * FROM website_profiles ORDER BY name,id'); }
    public static function activity(): array {
        return Db::all('SELECT o.*,p.name website_name,p.business_name FROM website_operations o JOIN website_profiles p ON p.id=o.website_id ORDER BY o.id DESC LIMIT 20');
    }
    public static function profile(int $id): array {
        $row=Db::one('SELECT * FROM website_profiles WHERE id=?',[$id]);
        if (!$row) throw new InvalidArgumentException('Website profile not found.');
        return $row;
    }
    private static function text(mixed $value,string $label,int $max,bool $required=false): string {
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")) throw new InvalidArgumentException('Enter a valid '.$label.'.');
        $value=trim($value);
        if (($required && $value==='') || mb_strlen($value)>$max) throw new InvalidArgumentException('Enter a valid '.$label.' of up to '.$max.' characters.');
        return $value;
    }
    public static function save(?int $id,array $data): int {
        $existing=$id ? self::profile($id) : null;
        $name=self::text($data['name'] ?? '', 'website name',120,true);
        $base=WebsiteHttpClient::normalizeUrl(self::text($data['base_url'] ?? '', 'website URL',2048,true));
        $connector=WebsiteHttpClient::normalizeUrl(self::text($data['connector_url'] ?? '', 'connector URL',2048),true);
        $server=self::text($data['server_address'] ?? '', 'server address',255);
        if ($server!=='' && !preg_match('/^[a-zA-Z0-9.\-:\[\]]+$/D',$server)) throw new InvalidArgumentException('Server address is a hostname or IP for your reference. Do not enter credentials.');
        $stack=self::text($data['stack'] ?? '', 'technology',100);
        $notes=self::text($data['notes'] ?? '', 'notes',2000);
        $business=self::text($data['business_name'] ?? ($existing['business_name'] ?? ''),'business name',120);
        if ($id) {
            Db::run('UPDATE website_profiles SET name=?,base_url=?,server_address=?,stack=?,connector_url=?,notes=?,business_name=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$name,$base,$server,$stack,$connector,$notes,$business,$id]);
            self::disconnect($id); return $id;
        }
        return Db::run('INSERT INTO website_profiles(name,base_url,server_address,stack,connector_url,notes,business_name) VALUES (?,?,?,?,?,?,?)',[$name,$base,$server,$stack,$connector,$notes,$business]);
    }
    public static function remove(int $id): void {
        if(Db::one('SELECT id FROM website_studio_records WHERE website_id=? LIMIT 1',[$id]) || Db::one('SELECT id FROM website_studio_media WHERE website_id=? LIMIT 1',[$id]))throw new InvalidArgumentException('This website has Admin Studio records or media. Keep its profile to preserve that workspace.');
        if (Db::one('SELECT id FROM website_costs WHERE website_id=? LIMIT 1',[$id])) throw new InvalidArgumentException('This website has maintenance costs. Keep its profile to retain the history, or remove/reverse those costs first.');
        self::profile($id); self::disconnect($id); Db::run('DELETE FROM website_profiles WHERE id=?',[$id]);
    }
    public static function disconnect(int $id): void { unset($_SESSION[self::SESSION_KEY][$id]); }
    public static function logout(int $id): void {
        $bundle=self::load($id);
        try {
            if (($bundle['auth_mode'] ?? '')==='password') WebsiteHttpClient::request(self::profile($id)['connector_url'],$bundle['token'],['protocol'=>'lifeos-admin-v1','action'=>'logout']);
        } finally { self::disconnect($id); }
    }
    public static function disconnectAll(): void { unset($_SESSION[self::SESSION_KEY]); if(class_exists('HubClientService'))HubClientService::disconnect(); }
    private static function key(): string {
        $raw=base64_decode(getenv('LIFEOS_WEBSITE_KEY') ?: '',true);
        if ($raw===false || strlen($raw)!==32) throw new InvalidArgumentException('Open the updated LifeOS desktop app to connect securely. Development servers need a random 32-byte LIFEOS_WEBSITE_KEY encoded as base64.');
        return $raw;
    }
    private static function store(int $id,array $bundle): void {
        $key=self::key(); $iv=random_bytes(12); $tag='';
        $aad='lifeos-websites:'.$id.':'.self::profile($id)['connector_url'];
        $cipher=openssl_encrypt(json_encode($bundle,JSON_THROW_ON_ERROR),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad,16);
        if ($cipher===false) throw new RuntimeException('The website session could not be protected.');
        $_SESSION[self::SESSION_KEY][$id]=base64_encode($iv.$tag.$cipher);
    }
    private static function load(int $id): ?array {
        $sealed=$_SESSION[self::SESSION_KEY][$id] ?? null;
        if (!is_string($sealed)) return null;
        try {
            $key=self::key(); $raw=base64_decode($sealed,true);
            if ($raw===false || strlen($raw)<29) throw new RuntimeException('Invalid session.');
            $aad='lifeos-websites:'.$id.':'.self::profile($id)['connector_url'];
            $json=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$aad);
            if ($json===false) throw new RuntimeException('Invalid session.');
            $bundle=json_decode($json,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($bundle) || !isset($bundle['expires_at'],$bundle['token'],$bundle['manifest']) || !is_int($bundle['expires_at']) || $bundle['expires_at']<=time()) throw new RuntimeException('Expired session.');
            return $bundle;
        } catch (Throwable) { self::disconnect($id); return null; }
    }
    private static function requireSession(int $id): array {
        self::profile($id);
        return self::load($id) ?? throw new InvalidArgumentException('Connect to this website again. Connections expire after 30 minutes or when LifeOS closes.');
    }
    public static function session(int $id): ?array {
        $bundle=self::load($id);
        return $bundle ? ['manifest'=>$bundle['manifest'],'expires_at'=>$bundle['expires_at'],'username'=>$bundle['username'] ?? '','auth_mode'=>$bundle['auth_mode'] ?? 'token'] : null;
    }
    private static function identifier(mixed $value,string $label='collection'): string {
        if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$value)) throw new InvalidArgumentException('The connector returned an invalid '.$label.' identifier.');
        return $value;
    }
    private static function recordId(mixed $value): string {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$value)) throw new InvalidArgumentException('Choose a valid website record.');
        return $value;
    }
    private static function manifest(array $raw): array {
        $site=$raw['site'] ?? null; $collections=$raw['collections'] ?? null;
        if (!is_array($site) || !is_array($collections) || !array_is_list($collections) || count($collections)>30) throw new InvalidArgumentException('The connector manifest is invalid or too large.');
        $result=['protocol'=>'lifeos-admin-v1','site'=>['name'=>self::text($site['name'] ?? '', 'connector site name',120,true)],'collections'=>[]]; $ids=[];
        foreach ($collections as $collection) {
            if (!is_array($collection)) throw new InvalidArgumentException('The connector collection definition is invalid.');
            $id=self::identifier($collection['id'] ?? null);
            if (isset($ids[$id])) throw new InvalidArgumentException('The connector has duplicate collection identifiers.');
            $ids[$id]=true; $fields=$collection['fields'] ?? null; $capabilities=$collection['capabilities'] ?? null;
            if (!is_array($fields) || !array_is_list($fields) || !$fields || count($fields)>40 || !is_array($capabilities) || !array_is_list($capabilities) || count(array_filter($capabilities,'is_string'))!==count($capabilities) || !in_array('list',$capabilities,true) || array_diff($capabilities,['list','create','update','delete']) || count(array_unique($capabilities,SORT_REGULAR))!==count($capabilities)) throw new InvalidArgumentException('The connector fields or permissions are unsupported.');
            $clean=['id'=>$id,'label'=>self::text($collection['label'] ?? '', 'collection label',100,true),'fields'=>[],'capabilities'=>$capabilities]; $names=[];
            foreach ($fields as $field) {
                if (!is_array($field)) throw new InvalidArgumentException('The connector field definition is invalid.');
                $name=self::identifier($field['name'] ?? null,'field'); $type=$field['type'] ?? null;
                if (isset($names[$name]) || !is_string($type) || !in_array($type,self::FIELD_TYPES,true) || !is_bool($field['required'] ?? null)) throw new InvalidArgumentException('The connector contains duplicate, invalid or unsupported fields.');
                $names[$name]=true; $max=$field['max_length'] ?? ($type==='textarea' ? 20000 : 2000);
                if (!is_int($max) || $max<1 || $max>20000) throw new InvalidArgumentException('The connector field length is unsupported.');
                $clean['fields'][]=['name'=>$name,'label'=>self::text($field['label'] ?? '', 'field label',100,true),'type'=>$type,'required'=>$field['required'],'max_length'=>$max];
            }
            $result['collections'][]=$clean;
        }
        return $result;
    }
    private static function log(int $id,string $action,string $collection='',string $record='',string $outcome='succeeded'): void {
        Db::run('INSERT INTO website_operations(website_id,action,collection,record_id,outcome) VALUES (?,?,?,?,?)',[$id,$action,$collection,$record,$outcome]);
    }
    public static function operations(int $id): array { self::profile($id); return Db::all('SELECT * FROM website_operations WHERE website_id=? ORDER BY id DESC LIMIT 50',[$id]); }
    public static function connect(int $id,string $token): void {
        $profile=self::profile($id); self::key(); self::disconnect($id);
        if ($profile['connector_url']==='') throw new InvalidArgumentException('Add the website connector HTTPS endpoint before connecting.');
        try {
            $raw=WebsiteHttpClient::request($profile['connector_url'],$token,['protocol'=>'lifeos-admin-v1','action'=>'manifest']);
            $manifest=self::manifest($raw);
            self::store($id,['token'=>$token,'manifest'=>$manifest,'expires_at'=>time()+self::TTL,'cache'=>[]]);
            Db::run('UPDATE website_profiles SET last_connected_at=CURRENT_TIMESTAMP WHERE id=?',[$id]);
            self::log($id,'connect');
        } catch (Throwable $e) { self::disconnect($id); self::log($id,'connect','','','failed'); throw $e; }
    }
    public static function login(int $id,string $username,string $password): void {
        $profile=self::profile($id); self::key(); self::disconnect($id);
        $username=self::text($username,'website username',120,true);
        if ($password==='' || strlen($password)>4096 || str_contains($password,"\0")) throw new InvalidArgumentException('Enter a website password of up to 4096 bytes.');
        if ($profile['connector_url']==='') throw new InvalidArgumentException('Add the website connector HTTPS endpoint before signing in.');
        $token=null;
        try {
            $response=WebsiteHttpClient::request($profile['connector_url'],null,['protocol'=>'lifeos-admin-v1','action'=>'login','username'=>$username,'password'=>$password]);
            $token=$response['token'] ?? null; $expires=$response['expires_at'] ?? null;
            if (!is_string($token) || !preg_match('/^[\x21-\x7e]{32,4096}$/D',$token) || !is_int($expires) || $expires<=time()) throw new InvalidArgumentException('The website returned an invalid login session.');
            $raw=WebsiteHttpClient::request($profile['connector_url'],$token,['protocol'=>'lifeos-admin-v1','action'=>'manifest']);
            self::store($id,['token'=>$token,'manifest'=>self::manifest($raw),'expires_at'=>min($expires,time()+self::TTL),'cache'=>[],'auth_mode'=>'password','username'=>$username]);
            Db::run('UPDATE website_profiles SET last_connected_at=CURRENT_TIMESTAMP WHERE id=?',[$id]); self::log($id,'login');
        } catch (Throwable $e) {
            if (is_string($token) && preg_match('/^[\x21-\x7e]{32,4096}$/D',$token)) {
                try { WebsiteHttpClient::request($profile['connector_url'],$token,['protocol'=>'lifeos-admin-v1','action'=>'logout']); } catch (Throwable) {}
            }
            self::disconnect($id); self::log($id,'login','','','failed'); throw $e;
        }
    }
    public static function loginGateway(string $url,string $username,string $password): int {
        $url=rtrim(WebsiteHttpClient::normalizeUrl($url),'/');self::key();
        $username=self::text($username,'gateway username',120,true);
        if($password==='' || strlen($password)>4096 || str_contains($password,"\0")) throw new InvalidArgumentException('Enter your gateway password.');
        $login=WebsiteHttpClient::request($url,null,['protocol'=>'lifeos-admin-v1','action'=>'login','username'=>$username,'password'=>$password]);
        $rootToken=$login['token'] ?? null;$prepared=[];$committed=false;
        if(!is_string($rootToken) || !preg_match('/^[\x21-\x7e]{32,4096}$/D',$rootToken))throw new InvalidArgumentException('Invalid gateway session.');
        try {
            $catalog=WebsiteHttpClient::request($url,$rootToken,['protocol'=>'lifeos-admin-v1','action'=>'sites']);
            $sites=$catalog['sites'] ?? null;
            if(!is_array($sites) || !array_is_list($sites) || !$sites || count($sites)>30) throw new InvalidArgumentException('The gateway must assign 1 to 30 websites to your account.');
            $slugs=[];$bases=[];
            foreach($sites as $site) {
                if(!is_array($site))throw new InvalidArgumentException('Invalid gateway website.');
                $slug=self::identifier($site['slug'] ?? null,'website');$endpoint=$url.'/sites/'.$slug;
                $name=self::text($site['name'] ?? '','website name',120,true);$business=self::text($site['business_name'] ?? '','business name',120);
                $base=WebsiteHttpClient::normalizeUrl($site['base_url'] ?? '');
                if(isset($slugs[$slug]) || isset($bases[$base]) || ($site['connector_url'] ?? '')!==$endpoint)throw new InvalidArgumentException('The gateway returned duplicate or unexpected website endpoints.');
                $slugs[$slug]=true;$bases[$base]=true;
                $matches=Db::all('SELECT * FROM website_profiles WHERE base_url=?',[$base]);
                if(count($matches)>1 || ($matches && $matches[0]['connector_url']!=='' && $matches[0]['connector_url']!==$endpoint))throw new InvalidArgumentException('An existing website profile uses a different connection. Clear its endpoint in Website details before importing from this gateway.');
                $session=WebsiteHttpClient::request($endpoint,$rootToken,['protocol'=>'lifeos-admin-v1','action'=>'site_session']);
                $token=$session['token'] ?? null;$expires=$session['expires_at'] ?? null;
                if(!is_string($token) || !preg_match('/^[\x21-\x7e]{32,4096}$/D',$token) || !is_int($expires) || $expires<=time())throw new InvalidArgumentException('Invalid website session from gateway.');
                $prepared[]=['existing'=>$matches[0] ?? null,'name'=>$name,'business'=>$business,'base'=>$base,'endpoint'=>$endpoint,'bundle'=>['token'=>$token,'expires_at'=>min($expires,time()+self::TTL),'cache'=>[],'auth_mode'=>'password','username'=>$username]];
                $index=array_key_last($prepared);
                $prepared[$index]['bundle']['manifest']=self::manifest(WebsiteHttpClient::request($endpoint,$token,['protocol'=>'lifeos-admin-v1','action'=>'manifest']));
            }
            $saved=Db::tx(function()use($prepared):array{
                $saved=[];
                foreach($prepared as $item) {
                    $existing=$item['existing'];
                    if($existing){$id=(int)$existing['id'];Db::run('UPDATE website_profiles SET connector_url=?,last_connected_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$item['endpoint'],$id]);}
                    else{$id=Db::run('INSERT INTO website_profiles(name,base_url,business_name,stack,connector_url,last_connected_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)',[$item['name'],$item['base'],$item['business'],'Managed gateway',$item['endpoint']]);}
                    self::log($id,'gateway_login');$saved[$id]=$item['bundle'];
                }
                return $saved;
            });
            foreach($saved as $id=>$bundle)self::store($id,$bundle);
            $committed=true;return count($saved);
        }finally{
            try{WebsiteHttpClient::request($url,$rootToken,['protocol'=>'lifeos-admin-v1','action'=>'logout']);}catch(Throwable){}
            if(!$committed)foreach($prepared as $item)try{WebsiteHttpClient::request($item['endpoint'],$item['bundle']['token'],['protocol'=>'lifeos-admin-v1','action'=>'logout']);}catch(Throwable){}
        }
    }
    public static function collection(int $id,string $slug): array {
        $bundle=self::requireSession($id);
        foreach ($bundle['manifest']['collections'] as $collection) if ($collection['id']===$slug) return $collection;
        throw new InvalidArgumentException('Choose a collection exposed by this website connector.');
    }
    private static function requireCapability(array $collection,string $operation): void {
        if (!in_array($operation,$collection['capabilities'],true)) throw new InvalidArgumentException('This connector does not allow '.$operation.' operations for this collection.');
    }
    private static function data(array $collection,array $values): array {
        $names=array_column($collection['fields'],'name');
        if (array_diff(array_keys($values),$names)) throw new InvalidArgumentException('The record contains fields that are not exposed by this connector.');
        $clean=[];
        foreach ($collection['fields'] as $field) {
            $value=$values[$field['name']] ?? ($field['type']==='boolean' ? false : '');
            if ($field['type']==='boolean') {
                if (!in_array($value,[true,false,0,1,'0','1'],true)) throw new InvalidArgumentException('Choose a valid value for '.$field['label'].'.');
                $clean[$field['name']]=in_array($value,[true,1,'1'],true); continue;
            }
            if ($field['type']==='number') {
                if (($value==='' || $value===null) && !$field['required']) { $clean[$field['name']]=null; continue; }
                if ((!is_string($value) && !is_int($value) && !is_float($value)) || (is_string($value) && !preg_match('/^-?\d{1,13}(?:\.\d{1,8})?$/D',$value)) || !is_finite((float)$value) || abs((float)$value)>1000000000000) throw new InvalidArgumentException('Enter a valid number for '.$field['label'].'.');
                $clean[$field['name']]=(float)$value; continue;
            }
            $value=self::text($value,$field['label'],$field['max_length'],$field['required']);
            if ($field['type']==='date' && $value!=='') valid_date($value);
            $clean[$field['name']]=$value;
        }
        return $clean;
    }
    private static function record(array $raw,array $collection): array {
        if (!is_array($raw['data'] ?? null) || !is_int($raw['version'] ?? null) || $raw['version']<1 || $raw['version']>2147483647) throw new InvalidArgumentException('The connector returned an invalid record or version.');
        return ['id'=>self::recordId($raw['id'] ?? null),'version'=>$raw['version'],'data'=>self::data($collection,$raw['data'])];
    }
    public static function refresh(int $id,string $slug,int $offset=0): void {
        if ($offset<0 || $offset>1000000) throw new InvalidArgumentException('Choose a valid record page.');
        $collection=self::collection($id,$slug); self::requireCapability($collection,'list'); $bundle=self::requireSession($id);
        try {
            $raw=WebsiteHttpClient::request(self::profile($id)['connector_url'],$bundle['token'],['protocol'=>'lifeos-admin-v1','action'=>'list','collection'=>$slug,'offset'=>$offset,'limit'=>50]);
            if (!is_array($raw['records'] ?? null) || !array_is_list($raw['records']) || count($raw['records'])>50 || !is_int($raw['total'] ?? null) || $raw['total']<0 || $raw['total']>1000000 || ($raw['offset'] ?? null)!==$offset || ($raw['limit'] ?? null)!==50) throw new InvalidArgumentException('The connector returned an invalid record page.');
            $records=[]; $seen=[];
            foreach ($raw['records'] as $row) {
                if (!is_array($row)) throw new InvalidArgumentException('The connector returned an invalid record.');
                $row=self::record($row,$collection);
                if (isset($seen[$row['id']])) throw new InvalidArgumentException('The connector returned duplicate record identifiers.');
                $seen[$row['id']]=true; $records[]=$row;
            }
            if (count($records)>$raw['total'] || ($offset<$raw['total'] && !$records)) throw new InvalidArgumentException('The connector record count is inconsistent.');
            $bundle['cache'][$slug]=['records'=>$records,'total'=>$raw['total'],'offset'=>$offset,'limit'=>50,'fetched_at'=>date('Y-m-d H:i:s')];
            self::store($id,$bundle); self::log($id,'refresh',$slug);
        } catch (Throwable $e) { self::log($id,'refresh',$slug,'','failed'); throw $e; }
    }
    public static function cached(int $id,string $slug): ?array { $bundle=self::load($id); return $bundle['cache'][$slug] ?? null; }
    public static function saveRecord(int $id,string $slug,array $values,?string $recordId=null,?int $version=null): void {
        $collection=self::collection($id,$slug); $action=$recordId===null ? 'create' : 'update';
        self::requireCapability($collection,$action); $data=self::data($collection,$values); $bundle=self::requireSession($id);
        $payload=['protocol'=>'lifeos-admin-v1','action'=>$action,'collection'=>$slug,'data'=>$data];
        if ($recordId!==null) {
            $payload['id']=self::recordId($recordId);
            if ($version===null || $version<1 || $version>2147483647) throw new InvalidArgumentException('Refresh the record to get its current version before editing.');
            $payload['version']=$version;
        }
        try {
            $raw=WebsiteHttpClient::request(self::profile($id)['connector_url'],$bundle['token'],$payload);
            if (!is_array($raw['record'] ?? null)) throw new InvalidArgumentException('The connector did not confirm the saved record. Refresh before trying again.');
            $saved=self::record($raw['record'],$collection);
            if ($recordId!==null && ($saved['id']!==$recordId || $saved['version']<=$version)) throw new InvalidArgumentException('The connector returned an inconsistent saved-record version. Refresh before trying again.');
            unset($bundle['cache'][$slug]); self::store($id,$bundle); self::log($id,$action,$slug,$saved['id']);
        } catch (Throwable $e) { self::log($id,$action,$slug,$recordId ?? '','failed'); throw $e; }
    }
    public static function deleteRecord(int $id,string $slug,string $recordId,int $version): void {
        $recordId=self::recordId($recordId);
        if ($version<1 || $version>2147483647) throw new InvalidArgumentException('Refresh the record to get its current version before deleting.');
        $collection=self::collection($id,$slug); self::requireCapability($collection,'delete'); $bundle=self::requireSession($id);
        try {
            $raw=WebsiteHttpClient::request(self::profile($id)['connector_url'],$bundle['token'],['protocol'=>'lifeos-admin-v1','action'=>'delete','collection'=>$slug,'id'=>$recordId,'version'=>$version]);
            if (($raw['deleted'] ?? null)!==true) throw new InvalidArgumentException('The connector did not confirm deletion. Refresh before trying again.');
            unset($bundle['cache'][$slug]); self::store($id,$bundle); self::log($id,'delete',$slug,$recordId);
        } catch (Throwable $e) { self::log($id,'delete',$slug,$recordId,'failed'); throw $e; }
    }
}
