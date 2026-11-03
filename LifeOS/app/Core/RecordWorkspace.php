<?php
declare(strict_types=1);
/** Typed local records shared by the employee and academic tools. */
abstract class RecordWorkspace {
    protected const TABLE='';
    abstract public static function catalog():array;
    public static function definition(string $kind):array{return static::catalog()[$kind]??throw new InvalidArgumentException(t('Choose a supported workspace page.'));}
    public static function rows(string $kind,string $search=''):array{static::definition($kind);$rows=Db::all('SELECT * FROM '.static::TABLE.' WHERE kind=? AND archived=0 ORDER BY id DESC',[$kind]);foreach($rows as &$row)$row['fields']=json_decode($row['data'],true,32,JSON_THROW_ON_ERROR);unset($row);return $search===''?$rows:array_values(array_filter($rows,fn($r)=>mb_stripos($r['data'],$search)!==false));}
    public static function get(int $id,string $kind):array{$row=Db::one('SELECT * FROM '.static::TABLE.' WHERE id=? AND kind=? AND archived=0',[$id,$kind]);if(!$row)throw new InvalidArgumentException(t('Record not found.'));$row['fields']=json_decode($row['data'],true,32,JSON_THROW_ON_ERROR);return $row;}
    public static function options(string $type):array{return str_starts_with($type,'enum:')?array_combine(explode(',',substr($type,5)),explode(',',substr($type,5))):[];}
    public static function normalizeInput(string $kind,array $input,?int $id=null):array{
        [,$definitions]=static::definition($kind);$data=[];
        foreach($definitions as $field=>$type){$optional=str_starts_with($type,'?');$type=ltrim($type,'?');$v=$input[$field]??'';if(!is_string($v)||str_contains($v,"\0")||mb_strlen($v)>20000)throw new InvalidArgumentException(t('Enter a valid ').$field.'.');$v=trim($v);if($v===''){if(!$optional)throw new InvalidArgumentException(t('Enter ').$field.'.');$data[$field]=null;continue;}
            if($type==='date')valid_date($v);elseif($type==='month')valid_month($v);elseif($type==='time'&&!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$v))throw new InvalidArgumentException(t('Use a valid time.'));
            elseif($type==='money'||$type==='money0')$v=cents($v,$type==='money0')/100;
            elseif(str_starts_with($type,'int:')){[, $min,$max]=explode(':',$type);$v=int_range($v,(int)$min,(int)$max);}
            elseif($type==='decimal'){if(!preg_match('/^\d{1,8}(?:\.\d{1,4})?$/D',$v))throw new InvalidArgumentException(t('Use a non-negative decimal number.'));$v=(float)$v;}
            elseif($type==='url'){if(!filter_var($v,FILTER_VALIDATE_URL)||!str_starts_with($v,'https://'))throw new InvalidArgumentException(t('Use a complete HTTPS link.'));}
            elseif(str_starts_with($type,'ref:')){$v=record_id($v);static::get($v,substr($type,4));}
            elseif(($options=static::options($type))&&!isset($options[$v]))throw new InvalidArgumentException(t('Choose a valid ').$field.'.');
            $data[$field]=$v;
        }
        if($id!==null)static::get($id,$kind);return static::validate($kind,$data,$id);
    }
    public static function save(string $kind,array $input,?int $id=null):int{
        $data=static::normalizeInput($kind,$input,$id);$json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if($id===null)return Db::run('INSERT INTO '.static::TABLE.'(kind,data) VALUES(?,?)',[$kind,$json]);Db::run('UPDATE '.static::TABLE.' SET data=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$json,$id]);return $id;
    }
    protected static function validate(string $kind,array $data,?int $id):array{return $data;}
    public static function archive(string $kind,int $id):void{static::get($id,$kind);foreach(static::catalog()as $other=>[,$fields])foreach($fields as $field=>$type)if(ltrim($type,'?')==='ref:'.$kind)foreach(static::rows($other)as $row)if(($row['fields'][$field]??null)===$id)throw new InvalidArgumentException(t('Archive linked ').$other.' records first.');Db::run('UPDATE '.static::TABLE.' SET archived=1,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$id]);}
    public static function dateRange(string $start,string $end):void{if($end<$start)throw new InvalidArgumentException(t('End date must be after the start date.'));}
    public static function task(int $record,string $kind,string $title,string $due):int{static::get($record,$kind);return Db::tx(function()use($record,$kind,$title,$due){$key=static::TABLE.':'.$kind.':'.$record;$row=Db::one('SELECT task_id FROM workspace_task_links WHERE identity=?',[$key]);if($row)return (int)$row['task_id'];$id=PlannerService::saveTask(null,['title'=>$title,'due'=>$due]);Db::run('INSERT INTO workspace_task_links(identity,task_id) VALUES(?,?)',[$key,$id]);return $id;});}
}
