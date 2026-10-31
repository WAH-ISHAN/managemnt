<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/Accounts.php';

final class LifeOSConnectorError extends RuntimeException {
    public function __construct(public readonly int $status,string $message) { parent::__construct($message); }
}

/** Reference content adapter. Adapt this class to an existing site's own models. */
final class LifeOSWebsiteConnector {
    public const PROTOCOL='lifeos-admin-v1';
    private const MAX_BODY=262144;
    private PDO $db;

    public static function collections(): array {
        $field=static fn(string $name,string $label,string $type,bool $required=false,int $max=2000): array=>['name'=>$name,'label'=>$label,'type'=>$type,'required'=>$required]+(in_array($type,['text','textarea'],true) ? ['max_length'=>$max] : []);
        return [
            ['id'=>'pages','label'=>'Pages','fields'=>[
                $field('title','Title','text',true,200),$field('slug','URL slug','text',true,150),
                $field('body','Page text','textarea',false,20000),$field('published','Published','boolean'),
                $field('published_on','Publication date','date')
            ],'capabilities'=>['list','create','update','delete']],
            ['id'=>'products','label'=>'Products','fields'=>[
                $field('name','Product name','text',true,200),$field('sku','SKU','text',true,80),
                $field('description','Description','textarea',false,10000),$field('price','Price','number',true),
                $field('published','Published','boolean')
            ],'capabilities'=>['list','create','update','delete']]
        ];
    }

    public function __construct(string $path,private ?array $permissions=null) {
        $this->db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->db->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL;');
        $this->db->exec("CREATE TABLE IF NOT EXISTS lifeos_records(collection TEXT NOT NULL,id TEXT NOT NULL,version INTEGER NOT NULL CHECK(version>=1),data TEXT NOT NULL,unique_key TEXT NOT NULL,updated_at TEXT NOT NULL,PRIMARY KEY(collection,id),UNIQUE(collection,unique_key));
            CREATE TABLE IF NOT EXISTS lifeos_revisions(id INTEGER PRIMARY KEY,collection TEXT NOT NULL,record_id TEXT NOT NULL,version INTEGER NOT NULL,action TEXT NOT NULL,previous_data TEXT,current_data TEXT,changed_at TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS lifeos_record_order ON lifeos_records(collection,updated_at,id);
            CREATE INDEX IF NOT EXISTS lifeos_revision_record ON lifeos_revisions(collection,record_id,id);");
        if (PHP_OS_FAMILY!=='Windows') @chmod($path,0600);
    }

    public function dispatch(array $request): array {
        if (($request['protocol'] ?? null)!==self::PROTOCOL) throw new LifeOSConnectorError(422,'Unsupported connector protocol.');
        $action=$request['action'] ?? null;
        if (!is_string($action) || !in_array($action,['manifest','list','create','update','delete'],true)) throw new LifeOSConnectorError(422,'Choose a supported action.');
        $allowed=match($action) {
            'manifest'=>['protocol','action'], 'list'=>['protocol','action','collection','offset','limit'],
            'create'=>['protocol','action','collection','data'], 'update'=>['protocol','action','collection','id','version','data'],
            'delete'=>['protocol','action','collection','id','version']
        };
        if (array_diff(array_keys($request),$allowed)) throw new LifeOSConnectorError(422,'Unknown request fields.');
        if ($action==='manifest') {
            $collections=[];
            foreach (self::collections() as $item) {
                if ($this->permissions!==null) $item['capabilities']=array_values(array_intersect($item['capabilities'],$this->permissions[$item['id']] ?? []));
                if (in_array('list',$item['capabilities'],true)) $collections[]=$item;
            }
            return ['protocol'=>self::PROTOCOL,'site'=>['name'=>lifeos_connector_site_name()],'collections'=>$collections];
        }
        $collection=$this->collection($request['collection'] ?? null);
        if (!in_array($action,$collection['capabilities'],true) || ($this->permissions!==null && !in_array($action,$this->permissions[$collection['id']] ?? [],true))) throw new LifeOSConnectorError(403,'This action is not enabled for the collection.');
        if ($action==='list') {
            $offset=$this->integer($request['offset'] ?? 0,0,10000000,'offset');
            $limit=$this->integer($request['limit'] ?? 50,1,50,'limit');
            $count=$this->db->prepare('SELECT COUNT(*) FROM lifeos_records WHERE collection=?'); $count->execute([$collection['id']]);
            $select=$this->db->prepare('SELECT * FROM lifeos_records WHERE collection=? ORDER BY updated_at DESC,id LIMIT ? OFFSET ?');
            $select->execute([$collection['id'],$limit,$offset]);
            return ['protocol'=>self::PROTOCOL,'records'=>array_map($this->record(...),$select->fetchAll()),'total'=>(int)$count->fetchColumn(),'offset'=>$offset,'limit'=>$limit];
        }
        $id=$action==='create' ? bin2hex(random_bytes(16)) : $this->id($request['id'] ?? null);
        $version=$action==='create' ? 0 : $this->integer($request['version'] ?? null,1,PHP_INT_MAX-1,'version');
        $data=$action==='delete' ? null : $this->data($collection,$request['data'] ?? null);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $old=null;
            if ($action!=='create') {
                $select=$this->db->prepare('SELECT * FROM lifeos_records WHERE collection=? AND id=?'); $select->execute([$collection['id'],$id]); $old=$select->fetch();
                if (!$old) throw new LifeOSConnectorError(404,'Record not found.');
                if ((int)$old['version']!==$version) throw new LifeOSConnectorError(409,'The record has changed. Refresh before trying again.');
            }
            $next=$version+1; $now=gmdate('Y-m-d\TH:i:s\Z');
            $encoded=$data===null ? null : json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if ($action==='delete') {
                $delete=$this->db->prepare('DELETE FROM lifeos_records WHERE collection=? AND id=? AND version=?'); $delete->execute([$collection['id'],$id,$version]);
            } elseif ($action==='create') {
                $insert=$this->db->prepare('INSERT INTO lifeos_records(collection,id,version,data,unique_key,updated_at) VALUES (?,?,?,?,?,?)');
                $insert->execute([$collection['id'],$id,$next,$encoded,$this->uniqueKey($collection['id'],$data),$now]);
            } else {
                $update=$this->db->prepare('UPDATE lifeos_records SET version=?,data=?,unique_key=?,updated_at=? WHERE collection=? AND id=? AND version=?');
                $update->execute([$next,$encoded,$this->uniqueKey($collection['id'],$data),$now,$collection['id'],$id,$version]);
            }
            $revision=$this->db->prepare('INSERT INTO lifeos_revisions(collection,record_id,version,action,previous_data,current_data,changed_at) VALUES (?,?,?,?,?,?,?)');
            $revision->execute([$collection['id'],$id,$next,$action,$old['data'] ?? null,$encoded,$now]);
            $this->db->exec('COMMIT');
            return $action==='delete' ? ['protocol'=>self::PROTOCOL,'deleted'=>true] : ['protocol'=>self::PROTOCOL,'record'=>['id'=>$id,'version'=>$next,'data'=>$data]];
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            if ($error instanceof PDOException && ($error->getCode()==='23000' || str_contains($error->getMessage(),'UNIQUE constraint failed'))) throw new LifeOSConnectorError(409,'A record with that slug or SKU already exists.');
            throw $error;
        }
    }

    private function collection(mixed $id): array {
        foreach(self::collections() as $collection) if ($collection['id']===$id) return $collection;
        throw new LifeOSConnectorError(422,'Unknown collection.');
    }
    private function integer(mixed $value,int $min,int $max,string $name): int {
        if (!is_int($value) || $value<$min || $value>$max) throw new LifeOSConnectorError(422,'Invalid '.$name.'.');
        return $value;
    }
    private function id(mixed $value): string {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D',$value)) throw new LifeOSConnectorError(422,'Invalid record id.');
        return $value;
    }
    private function record(array $row): array {
        return ['id'=>$row['id'],'version'=>(int)$row['version'],'data'=>json_decode($row['data'],true,32,JSON_THROW_ON_ERROR)];
    }
    private function uniqueKey(string $collection,array $data): string { return strtolower($data[$collection==='pages' ? 'slug' : 'sku']); }
    private function data(array $collection,mixed $input): array {
        if (!$input instanceof stdClass) throw new LifeOSConnectorError(422,'Record data must be an object.');
        $input=get_object_vars($input); $known=array_column($collection['fields'],'name');
        if (array_diff(array_keys($input),$known)) throw new LifeOSConnectorError(422,'Unknown record fields.');
        $data=[];
        foreach($collection['fields'] as $field) {
            $key=$field['name']; $type=$field['type']; $exists=array_key_exists($key,$input);
            if (!$exists && $field['required']) throw new LifeOSConnectorError(422,'Missing '.$field['label'].'.');
            $value=$exists ? $input[$key] : match($type) {'boolean'=>false,'number'=>0,default=>''};
            if ($type==='boolean') {
                if (!is_bool($value)) throw new LifeOSConnectorError(422,'Invalid '.$field['label'].'.');
            } elseif ($type==='number') {
                if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || abs((float)$value)>1000000000000) throw new LifeOSConnectorError(422,'Invalid '.$field['label'].'.');
                if ($key==='price' && $value<0) throw new LifeOSConnectorError(422,'Price cannot be negative.');
            } else {
                if (!is_string($value) || str_contains($value,"\0") || !mb_check_encoding($value,'UTF-8')) throw new LifeOSConnectorError(422,'Invalid '.$field['label'].'.');
                $value=trim($value);
                if (mb_strlen($value)>($field['max_length'] ?? 10) || ($field['required'] && $value==='')) throw new LifeOSConnectorError(422,'Invalid '.$field['label'].'.');
                if ($type==='date' && $value!=='') {
                    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
                    if (!$date || $date->format('Y-m-d')!==$value) throw new LifeOSConnectorError(422,'Invalid '.$field['label'].'.');
                }
            }
            $data[$key]=$value;
        }
        if ($collection['id']==='pages' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$data['slug'])) throw new LifeOSConnectorError(422,'Use a lowercase URL slug with letters, numbers and hyphens.');
        return $data;
    }

    public static function serve(): void {
        ini_set('display_errors','0');
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
        try {
            $https=in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')),['on','1'],true);
            $host=parse_url('http://'.($_SERVER['HTTP_HOST'] ?? ''),PHP_URL_HOST);
            $local=getenv('LIFEOS_CONNECTOR_ALLOW_LOCAL_HTTP')==='1' && in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true) && in_array($host,['127.0.0.1','localhost','[::1]'],true);
            if (!$https && !$local) throw new LifeOSConnectorError(403,'HTTPS is required.');
            if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { header('Allow: POST'); throw new LifeOSConnectorError(405,'Use POST with a JSON body.'); }
            $type=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
            if ($type!=='application/json') throw new LifeOSConnectorError(415,'Use application/json.');
            if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH']>self::MAX_BODY) throw new LifeOSConnectorError(413,'Request body is too large.');
            $body=file_get_contents('php://input',false,null,0,self::MAX_BODY+1);
            if (!is_string($body) || strlen($body)>self::MAX_BODY) throw new LifeOSConnectorError(413,'Request body is too large.');
            try { $request=json_decode($body,false,32,JSON_THROW_ON_ERROR); } catch (JsonException) { throw new LifeOSConnectorError(400,'Invalid JSON.'); }
            if (!$request instanceof stdClass) throw new LifeOSConnectorError(422,'The request must be an object.');
            $request=get_object_vars($request);
            $path=lifeos_connector_db_path();
            $accounts=new LifeOSConnectorAccounts($path);
            if (($request['action'] ?? null)==='login') {
                $response=$accounts->login($request,(string)($_SERVER['REMOTE_ADDR'] ?? ''));
            } else {
                $configured=getenv('LIFEOS_CONNECTOR_TOKEN');
                if (is_string($configured) && $configured!=='' && !preg_match('/^[\x21-\x7E]{32,4096}$/D',$configured)) throw new LifeOSConnectorError(503,'Connector authentication is not configured.');
                $authorization=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
                if (!is_string($authorization) || !preg_match('/^Bearer ([\x21-\x7e]{32,4096})$/D',$authorization,$match)) throw new LifeOSConnectorError(401,'Authentication failed.');
                $token=$match[1];
                $permissions=is_string($configured) && $configured!=='' && hash_equals($configured,$token) ? null : $accounts->authenticate($token);
                if (($request['action'] ?? null)==='logout') {
                    if (($request['protocol'] ?? null)!==self::PROTOCOL || array_diff(array_keys($request),['protocol','action'])) throw new LifeOSConnectorError(422,'Invalid logout request.');
                    $response=$accounts->logout($token);
                } else {
                    $connector=new self($path,$permissions);
                    $response=$connector->dispatch($request);
                }
            }
            echo json_encode($response,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        } catch (LifeOSConnectorError $error) {
            http_response_code($error->status); echo json_encode(['protocol'=>self::PROTOCOL,'error'=>$error->getMessage()],JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            http_response_code(503); echo json_encode(['protocol'=>self::PROTOCOL,'error'=>'Connector storage is unavailable. Check the private server configuration.'],JSON_THROW_ON_ERROR);
        }
    }
}
