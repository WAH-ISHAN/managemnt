<?php
declare(strict_types=1);
final class PlatformService {
    public static function assertUnreferenced(string $table,int $id):void{foreach(Db::all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")as $t){$quote='"'.str_replace('"','""',$t['name']).'"';foreach(Db::all('PRAGMA foreign_key_list('.$quote.')')as $fk)if($fk['table']===$table){$column='"'.str_replace('"','""',$fk['from']).'"';if(Db::one('SELECT 1 FROM '.$quote.' WHERE '.$column.'=? LIMIT 1',[$id]))throw new InvalidArgumentException(t('Another record now links to this entry. Undo stopped to preserve it.'));}}}
    public static function undoEdit(int $id):void{
        Db::tx(function()use($id){
            $row=Db::one('SELECT * FROM platform_history WHERE id=? AND undone=0',[$id]);
            if(!$row)throw new InvalidArgumentException(t('History entry is already undone or missing.'));
            $table=$row['entity'];
            $fields=match($table){'tasks'=>['title','due'],'life_contacts'=>['name','phone','email','notes'],'employee_records','study_records'=>['data','kind'],default=>throw new InvalidArgumentException(t('This record includes linked progress, approvals or financial state. Correct it from its module.'))};
            $current=Db::one('SELECT '.implode(',',$fields).' FROM '.$table.' WHERE id=?',[$row['row_id']]);
            if($current!==json_decode($row['after_json'],true))throw new InvalidArgumentException(t('Record has changed since this edit. Undo stopped.'));
            $before=json_decode($row['before_json'],true,32,JSON_THROW_ON_ERROR);
            if($table==='tasks'){
                $task=Db::one('SELECT source FROM tasks WHERE id=?',[$row['row_id']]);
                if(!in_array($task['source'],['planner','platform-import','device-sync'],true))throw new InvalidArgumentException(t('This task belongs to another workflow. Edit it from its source module.'));
                if(!module_enabled('Planner'))throw new InvalidArgumentException(t('Enable Planner first.'));
                if($before['due']!==null)valid_date($before['due']);
            }elseif($table==='life_contacts'){
                if(!module_enabled('LifePlanning')||Db::one("SELECT 1 FROM life_archives WHERE entity='contacts' AND row_id=?",[$row['row_id']]))throw new InvalidArgumentException(t('Enable the module and restore the contact before undo.'));
            }else{
                $service=$table==='employee_records'?EmployeeService::class:StudyService::class;
                $module=$table==='employee_records'?'Employee':'StudyTools';
                $allowed=$table==='employee_records'?['achievements','meetings','blockers','promotion']:['notes','portfolio','references'];
                if(!module_enabled($module)||!in_array($before['kind'],$allowed,true))throw new InvalidArgumentException(t('This record requires a correction through its module.'));
                $data=json_decode($before['data'],true,32,JSON_THROW_ON_ERROR);
                $service::save($before['kind'],array_map(fn($v)=>$v===null?'':(string)$v,$data),(int)$row['row_id'],false);
            }
            if(in_array($table,['tasks','life_contacts'],true)){
                $sets=implode(',',array_map(fn($f)=>$f.'=?',$fields));
                Db::run('UPDATE '.$table.' SET '.$sets.' WHERE id=?',[...array_map(fn($f)=>$before[$f],$fields),$row['row_id']]);
            }
            Db::run('UPDATE platform_history SET undone=1 WHERE id=?',[$id]);
            self::audit('edit-undone',$table.' #'.$row['row_id']);
        });
    }
    public static function audit(string $action,string $detail):void{$actor=class_exists('ProfileManager')?ProfileManager::id():'default';Db::run('INSERT INTO platform_audit(action,detail,actor) VALUES(?,?,?)',[$action,$detail,$actor]);}
    public static function preview(string $text,string $format):array{
        if(strlen($text)>2097152)throw new InvalidArgumentException(t('Import is limited to 2 MiB.'));
        if(!in_array($format,['csv','notion','todoist','json'],true))throw new InvalidArgumentException(t('Choose an import format.'));
        $rows=[];
        if($format==='json'){$raw=json_decode($text,true,32,JSON_THROW_ON_ERROR);if(!is_array($raw)||!array_is_list($raw))throw new InvalidArgumentException(t('Expected a JSON list.'));$rows=$raw;}
        else{$handle=fopen('php://temp','w+');fwrite($handle,$text);rewind($handle);$head=fgetcsv($handle,0,',','"','');if(!$head)throw new InvalidArgumentException(t('CSV needs a header.'));$head=array_map(fn($s)=>strtolower(trim(ltrim($s,"\xef\xbb\xbf"))),$head);while(($row=fgetcsv($handle,0,',','"',''))!==false){if($row===[null])continue;if(count($row)!==count($head))throw new InvalidArgumentException(t('CSV column count differs from header.'));$rows[]=array_combine($head,$row);if(count($rows)>1000)throw new InvalidArgumentException(t('Import at most 1000 records.'));}fclose($handle);}
        if(count($rows)>1000)throw new InvalidArgumentException(t('Import at most 1000 records.'));$out=[];
        foreach($rows as $row){if(!is_array($row))throw new InvalidArgumentException(t('Invalid row.'));if($format==='todoist'&&isset($row['type'])&&strtolower((string)$row['type'])!=='task')continue;$title=$row['title']??$row['name']??$row['content']??'';$due=$row['due']??$row['due date']??$row['date']??'';if(!is_string($title)||trim($title)===''||mb_strlen($title)>200||!is_string($due))throw new InvalidArgumentException(t('Every task needs a title (up to 200 characters) and optional ISO due date.'));if($due!=='')valid_date($due);$out[]=['title'=>trim($title),'due'=>$due===''?null:$due];}return $out;
    }
    public static function import(array $rows):int{
        $rows=self::preview(json_encode($rows,JSON_THROW_ON_ERROR),'json');$hash=hash('sha256',json_encode($rows));return Db::tx(function()use($rows,$hash){if(Db::one('SELECT id FROM platform_imports WHERE hash=?',[$hash]))throw new InvalidArgumentException(t('This exact import has already been applied.'));$batch=Db::run('INSERT INTO platform_imports(hash) VALUES(?)',[$hash]);foreach($rows as $row){$id=Db::run("INSERT INTO tasks(title,due,source) VALUES(?,?,'platform-import')",[$row['title'],$row['due']]);$saved=Db::one('SELECT * FROM tasks WHERE id=?',[$id]);Db::run('INSERT INTO platform_changes(batch_id,entity,row_id,after_json) VALUES(?,?,?,?)',[$batch,'tasks',$id,json_encode($saved,JSON_THROW_ON_ERROR)]);}self::audit('tasks-imported',count($rows).' tasks; batch '.$batch);return $batch;});
    }
    public static function undo(int $batch):void{$b=Db::one('SELECT target FROM platform_imports WHERE id=?',[$batch]);if(($b['target']??'tasks')!=='tasks'){PortableRecords::undo($batch);return;}Db::tx(function()use($batch){$rows=Db::all('SELECT * FROM platform_changes WHERE batch_id=? AND undone=0',[$batch]);if(!$rows)throw new InvalidArgumentException(t('No pending changes in this batch.'));foreach($rows as $change){$current=Db::one('SELECT * FROM tasks WHERE id=?',[$change['row_id']]);if($change['entity']!=='tasks'||$current!==json_decode($change['after_json'],true))throw new InvalidArgumentException(t('A record changed after import. Undo stopped to preserve later edits.'));}foreach($rows as $change){self::assertUnreferenced('tasks',(int)$change['row_id']);Db::run('DELETE FROM tasks WHERE id=?',[$change['row_id']]);Db::run('UPDATE platform_changes SET undone=1 WHERE id=?',[$change['id']]);}self::audit('import-undone','Batch '.$batch);});}
    public static function export():string{$f=fopen('php://temp','w+');fwrite($f,"\xef\xbb\xbf");fputcsv($f,['title','due','status'],',','"','');foreach(Db::all('SELECT title,due,status FROM tasks ORDER BY id')as $row){$row=array_map(fn($v)=>preg_match('/^[=+@\-\t\r]/',(string)$v)?"'".$v:$v,$row);fputcsv($f,$row,',','"','');}rewind($f);$out=stream_get_contents($f);fclose($f);return $out;}
}
