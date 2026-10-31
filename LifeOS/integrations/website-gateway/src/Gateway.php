<?php
declare(strict_types=1);
final class LifeOSConnectorError extends RuntimeException { public function __construct(public int $status,string $message){parent::__construct($message);} }
require_once __DIR__.'/Accounts.php';
if(!class_exists('WebsiteHttpClient')) require_once __DIR__.'/WebsiteHttpClient.php';

/** Operator-owned gateway. Client accounts never receive origin credentials. */
final class LifeOSGateway {
    private PDO $db;
    public LifeOSConnectorAccounts $accounts;
    public function __construct(string $path,private string $key,private string $publicUrl) {
        if(strlen($key)!==32) throw new RuntimeException('Configure a 32-byte gateway encryption key.');
        $this->publicUrl=WebsiteHttpClient::normalizeUrl($publicUrl);
        $this->accounts=new LifeOSConnectorAccounts($path);
        $this->db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=5000; CREATE TABLE IF NOT EXISTS gateway_sites(slug TEXT PRIMARY KEY,name TEXT NOT NULL,base_url TEXT NOT NULL,business_name TEXT NOT NULL,mode TEXT NOT NULL,endpoint TEXT NOT NULL,secret TEXT NOT NULL,definition TEXT NOT NULL,document TEXT NOT NULL,enabled INTEGER NOT NULL DEFAULT 1);');
        $this->db->exec('CREATE TABLE IF NOT EXISTS gateway_origin_sessions(slug TEXT PRIMARY KEY,secret TEXT NOT NULL,expires_at INTEGER NOT NULL);');
    }
    public function provision(array $site): void {
        $slug=$site['slug'] ?? '';
        if(!is_string($slug) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$slug)) throw new InvalidArgumentException('Use a site slug with letters, numbers and underscores.');
        foreach(['name'=>120,'business_name'=>120] as $field=>$max) if(!is_string($site[$field] ?? '') || mb_strlen($site[$field] ?? '')>$max || str_contains($site[$field] ?? '',"\0")) throw new InvalidArgumentException('Invalid site '.$field.'.');
        if(trim($site['name'] ?? '')==='') throw new InvalidArgumentException('Enter the website name.');
        $base=WebsiteHttpClient::normalizeUrl($site['base_url'] ?? ''); $mode=$site['mode'] ?? '';
        if(!in_array($mode,['proxy','content'],true)) throw new InvalidArgumentException('Choose proxy or content mode.');
        $endpoint='';$secret='';$definition=[];$document=[];
        if($mode==='proxy') {
            $endpoint=WebsiteHttpClient::normalizeUrl($site['endpoint'] ?? '');
            if(isset($site['username']) || isset($site['password'])) {
                if(!is_string($site['username'] ?? null) || $site['username']==='' || strlen($site['username'])>120 || !is_string($site['password'] ?? null) || $site['password']==='' || strlen($site['password'])>4096 || isset($site['token']))throw new InvalidArgumentException('Provide either origin token or origin account credentials.');
                $config=['mode'=>'password','username'=>$site['username'],'password'=>$site['password']];
            }else{
                $token=$site['token'] ?? '';
                if(!is_string($token) || !preg_match('/^[\x21-\x7e]{32,4096}$/D',$token)) throw new InvalidArgumentException('Invalid origin connector token.');
                $config=['mode'=>'token','token'=>$token];
            }
            $secret=$this->seal(json_encode($config,JSON_THROW_ON_ERROR),$slug);
        } else {
            $definition=$site['collections'] ?? []; $document=$site['document'] ?? [];
            if(!is_array($document) || array_diff(array_keys($document),['collections'])) throw new InvalidArgumentException('Only declared public collections may be published.');
            if(!is_array($definition) || !array_is_list($definition) || !$definition || count($definition)>30) throw new InvalidArgumentException('Provide a public content schema.');
            $ids=[];
            foreach($definition as $collection) {
                $id=$collection['id'] ?? '';
                if(!is_string($id) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$id) || isset($ids[$id]) || !is_array($collection['fields'] ?? null) || !$collection['fields'] || count($collection['fields'])>40 || ($collection['capabilities'] ?? [])!==['list','update'] || !is_string($collection['label'] ?? null)) throw new InvalidArgumentException('Content sites support unique list/update collections.');
                $ids[$id]=true; $rows=$document['collections'][$id] ?? null;
                if(!is_array($rows) || !array_is_list($rows) || count($rows)>50) throw new InvalidArgumentException('Invalid public content records.');
                $recordIds=[];
                foreach($rows as $row) {
                    if(!is_array($row) || array_diff(array_keys($row),['id','version','data'])) throw new InvalidArgumentException('Public records contain only id, version and data.');
                    if(!is_string($row['id'] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$row['id']) || isset($recordIds[$row['id']]) || !is_int($row['version'] ?? null) || $row['version']<1) throw new InvalidArgumentException('Invalid content record.');
                    $recordIds[$row['id']]=true; $this->validateData($collection,$row['data'] ?? null);
                }
            }
            if(array_diff(array_keys($document['collections'] ?? []),array_keys($ids))) throw new InvalidArgumentException('Only declared public collections may be published.');
        }
        if(strlen(json_encode($document,JSON_THROW_ON_ERROR))>524288) throw new InvalidArgumentException('Content is too large.');
        // Explicit provisioning never silently overwrites existing live content.
        $query=$this->db->prepare('INSERT INTO gateway_sites(slug,name,base_url,business_name,mode,endpoint,secret,definition,document) VALUES (?,?,?,?,?,?,?,?,?)');
        $query->execute([$slug,trim($site['name']),$base,$site['business_name'] ?? '',$mode,$endpoint,$secret,json_encode($definition,JSON_THROW_ON_ERROR),json_encode($document,JSON_THROW_ON_ERROR)]);
    }
    private function site(string $slug): array {
        $q=$this->db->prepare('SELECT * FROM gateway_sites WHERE slug=? AND enabled=1');$q->execute([$slug]);
        return $q->fetch() ?: throw new LifeOSConnectorError(404,'Website unavailable.');
    }
    private function seal(string $value,string $context):string {
        $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag,$context);
        if($cipher===false)throw new RuntimeException('Cannot encrypt gateway secret.');
        return base64_encode($iv.$tag.$cipher);
    }
    private function unseal(string $value,string $context):string {
        $raw=base64_decode($value,true);if($raw===false || strlen($raw)<29)throw new RuntimeException('Invalid gateway secret.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$context);
        if($plain===false)throw new RuntimeException('Cannot unlock gateway secret.');return $plain;
    }
    private function originToken(array $site):string {
        $config=json_decode($this->unseal($site['secret'],$site['slug']),true,8,JSON_THROW_ON_ERROR);
        if($config['mode']==='token')return $config['token'];
        $q=$this->db->prepare('SELECT secret FROM gateway_origin_sessions WHERE slug=? AND expires_at>?');$q->execute([$site['slug'],time()+30]);$cached=$q->fetchColumn();
        if(is_string($cached))return $this->unseal($cached,$site['slug'].':session');
        $session=WebsiteHttpClient::request($site['endpoint'],null,['protocol'=>'lifeos-admin-v1','action'=>'login','username'=>$config['username'],'password'=>$config['password']]);
        $token=$session['token'] ?? null;$expires=$session['expires_at'] ?? null;
        if(!is_string($token) || !preg_match('/^[\x21-\x7e]{32,4096}$/D',$token) || !is_int($expires) || $expires<=time()+30)throw new RuntimeException('Invalid origin session.');
        $q=$this->db->prepare('INSERT INTO gateway_origin_sessions(slug,secret,expires_at) VALUES (?,?,?) ON CONFLICT(slug) DO UPDATE SET secret=excluded.secret,expires_at=excluded.expires_at');$q->execute([$site['slug'],$this->seal($token,$site['slug'].':session'),$expires]);
        return $token;
    }
    public function publicContent(string $slug): array {
        $site=$this->site($slug);
        if($site['mode']!=='content') throw new LifeOSConnectorError(404,'Public content unavailable.');
        return json_decode($site['document'],true,32,JSON_THROW_ON_ERROR);
    }
    public function dispatch(string $slug,?string $token,array $request,string $ip): array {
        if(($request['protocol'] ?? '')!=='lifeos-admin-v1' || !is_string($request['action'] ?? null)) throw new LifeOSConnectorError(422,'Unsupported request.');
        $action=$request['action'];
        if($action==='login') {
            $session=$this->accounts->login($request,$ip);
            if($slug!=='') {
                try { $permissions=$this->accounts->authenticate($session['token']); if(!isset($permissions[$slug])) throw new LifeOSConnectorError(403,'Website access denied.');$this->site($slug); }
                catch(Throwable $e){$this->accounts->logout($session['token']);throw $e;}
            }
            return $session;
        }
        if($token===null || !preg_match('/^[\x21-\x7e]{32,4096}$/D',$token)) throw new LifeOSConnectorError(401,'Sign in to the gateway.');
        $permissions=$this->accounts->authenticate($token);
        if($slug==='') {
            if($action==='sites' && !array_diff(array_keys($request),['protocol','action'])) {
                $sites=[];
                foreach($this->db->query('SELECT slug,name,base_url,business_name FROM gateway_sites WHERE enabled=1 ORDER BY name') as $site) if(isset($permissions[$site['slug']])) $sites[]=[...$site,'connector_url'=>$this->publicUrl.'/sites/'.$site['slug']];
                return ['protocol'=>'lifeos-admin-v1','sites'=>$sites];
            }
            if($action==='logout') return $this->accounts->logout($token);
            throw new LifeOSConnectorError(422,'Choose a gateway action.');
        }
        if(!isset($permissions[$slug])) throw new LifeOSConnectorError(403,'Website access denied.');
        $site=$this->site($slug); $allowed=$permissions[$slug];
        if($action==='site_session' && !array_diff(array_keys($request),['protocol','action'])) return $this->accounts->forkSession($token);
        if($action==='logout') return $this->accounts->logout($token);
        $fields=match($action){'manifest'=>['protocol','action'],'list'=>['protocol','action','collection','offset','limit'],'update'=>['protocol','action','collection','id','version','data'],'create'=>['protocol','action','collection','data'],'delete'=>['protocol','action','collection','id','version'],default=>throw new LifeOSConnectorError(422,'Unsupported site action.')};
        if(array_diff(array_keys($request),$fields)) throw new LifeOSConnectorError(422,'Unknown request fields.');
        if($action!=='manifest' && !in_array($action,$allowed,true)) throw new LifeOSConnectorError(403,'This account cannot perform this action.');
        if($site['mode']==='proxy') {
            try{$result=WebsiteHttpClient::request($site['endpoint'],$this->originToken($site),$request);}catch(Throwable){throw new LifeOSConnectorError(502,'The website connector could not complete this request.');}
            if($action==='manifest') foreach($result['collections'] ?? [] as $i=>$collection) $result['collections'][$i]['capabilities']=array_values(array_intersect($collection['capabilities'],$allowed));
            return $result;
        }
        $definitions=json_decode($site['definition'],true,32,JSON_THROW_ON_ERROR);
        if($action==='manifest') {
            foreach($definitions as &$definition) $definition['capabilities']=array_values(array_intersect($definition['capabilities'],$allowed));unset($definition);
            return ['protocol'=>'lifeos-admin-v1','site'=>['name'=>$site['name']],'collections'=>$definitions];
        }
        $definition=null;foreach($definitions as $candidate)if($candidate['id']===($request['collection'] ?? null))$definition=$candidate;
        if(!$definition || !in_array($action,$definition['capabilities'],true)) throw new LifeOSConnectorError(403,'Collection action unavailable.');
        if($action==='list') {
            $offset=$request['offset'] ?? 0;$limit=$request['limit'] ?? 50;
            if(!is_int($offset) || $offset<0 || $offset>1000000 || !is_int($limit) || $limit<1 || $limit>50) throw new LifeOSConnectorError(422,'Invalid page.');
            $rows=$this->publicContent($slug)['collections'][$definition['id']];
            return ['protocol'=>'lifeos-admin-v1','records'=>array_slice($rows,$offset,$limit),'total'=>count($rows),'offset'=>$offset,'limit'=>$limit];
        }
        $data=$this->validateData($definition,$request['data'] ?? null);
        if(!is_string($request['id'] ?? null) || !is_int($request['version'] ?? null) || $request['version']<1 || $request['version']>=2147483647) throw new LifeOSConnectorError(422,'Invalid record version.');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $document=$this->publicContent($slug);$found=false;$record=null;
            foreach($document['collections'][$definition['id']] as &$row) if($row['id']===$request['id']) {
                if($row['version']!==$request['version']) throw new LifeOSConnectorError(409,'This record changed. Refresh before saving.');
                $row=['id'=>$row['id'],'version'=>$row['version']+1,'data'=>$data];$record=$row;$found=true;break;
            }unset($row);
            if(!$found) throw new LifeOSConnectorError(404,'Record not found.');
            if(isset($definition['unique_field'])) {
                $values=array_column(array_column($document['collections'][$definition['id']],'data'),$definition['unique_field']);
                if(count(array_unique($values))!==count($values)) throw new LifeOSConnectorError(422,'This field must stay unique within the collection.');
            }
            $json=json_encode($document,JSON_THROW_ON_ERROR);if(strlen($json)>524288)throw new LifeOSConnectorError(413,'Content too large.');
            $q=$this->db->prepare('UPDATE gateway_sites SET document=? WHERE slug=?');$q->execute([$json,$slug]);$this->db->exec('COMMIT');
            return ['protocol'=>'lifeos-admin-v1','record'=>$record];
        }catch(Throwable $e){$this->db->exec('ROLLBACK');throw $e;}
    }
    private function validateData(array $definition,mixed $data): array {
        if(!is_array($data) || array_diff(array_keys($data),array_column($definition['fields'],'name'))) throw new LifeOSConnectorError(422,'Invalid content fields.');
        $clean=[];$seen=[];
        foreach($definition['fields'] as $field) {
            $name=$field['name'] ?? '';$max=$field['max_length'] ?? 2000;
            if(!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$name) || isset($seen[$name]) || !in_array($field['type'] ?? '',['text','textarea'],true) || !is_int($max) || $max<1 || $max>20000 || !is_bool($field['required'] ?? null) || !is_string($field['label'] ?? null)) throw new LifeOSConnectorError(422,'Unsupported public content schema.');
            $seen[$name]=true;$value=$data[$name] ?? '';
            if(!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0") || mb_strlen($value)>$max || ($field['required'] && trim($value)==='')) throw new LifeOSConnectorError(422,'Complete valid content fields.');
            if(isset($field['allowed_values']) && (!is_array($field['allowed_values']) || !in_array(trim($value),$field['allowed_values'],true)))throw new LifeOSConnectorError(422,'Choose a permitted field value.');
            $clean[$name]=trim($value);
        }
        return $clean;
    }
}
