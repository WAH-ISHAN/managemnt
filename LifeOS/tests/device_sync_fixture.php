<?php
declare(strict_types=1);
$directory=$argv[1]??'';$normal=str_replace('\\','/',$directory);$temp=str_replace('\\','/',sys_get_temp_dir());if(!str_starts_with($normal,rtrim($temp,'/').'/lifeos-device-test-')||!preg_match('#/[ab]$#D',$normal)||!is_dir($directory))throw new RuntimeException('Use an isolated device test directory.');
require __DIR__.'/bootstrap.php';define('DATA',$directory);$request=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$op=$request['op']??'';if($op!=='identity'&&!is_file(DATA.'/.fixture'))throw new RuntimeException('Device fixture is not initialized.');
Db::init(DATA.'/lifeos.db');Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',Modules::discover(ROOT.'/app/Modules'))));
try{$result=match($op){
 'identity'=>(function(){file_put_contents(DATA.'/.fixture','disposable');$i=DeviceSyncService::identity();return array_intersect_key($i,array_flip(['id','public']));})(),
 'pair'=>(function()use($request){DeviceSyncService::pair('Paired fixture',$request['public']);return ['ok'=>true];})(),
 'seed'=>(function(){$task=PlannerService::saveTask(null,['title'=>'Original task','due'=>'2027-01-01']);$contact=LifePlanningService::save('contacts',['name'=>'Contact','phone'=>'071','email'=>'a@example.test','birthday'=>'2000-01-01','next_followup'=>'','notes'=>'Sample note']);$temp=tempnam(sys_get_temp_dir(),'lifeos-doc-');try{file_put_contents($temp,'Original attachment bytes');$doc=DocumentService::store($temp,'fixture.txt','Fixture file','Other','Sample document',DATA);}finally{unlink($temp);}return ['task'=>$task,'contact'=>$contact,'document'=>$doc];})(),
 'tasks_export'=>DeviceSyncService::packet($request['peer']),
 'tasks_preview'=>DeviceSyncService::preview($request['packet']),
 'tasks_apply'=>(function()use($request){DeviceSyncService::apply($request['packet'],$request['choices']??[]);return ['ok'=>true];})(),
 'objects_export'=>SecureObjectSync::packet($request['peer'],$request['contacts']??[],$request['documents']??[]),
 'objects_preview'=>(function()use($request){$p=SecureObjectSync::preview($request['packet']);foreach($p['rows']as &$row)unset($row['bytes']);return $p;})(),
 'objects_apply'=>(function()use($request){SecureObjectSync::apply($request['packet'],$request['choices']??[]);return ['ok'=>true];})(),
 'edit_task'=>(function()use($request){PlannerService::saveTask((int)$request['id'],['title'=>$request['title']]);return ['ok'=>true];})(),
 'edit_contact'=>(function()use($request){LifePlanningService::save('contacts',['name'=>$request['name']],(int)$request['id']);return ['ok'=>true];})(),
 'edit_document'=>(function()use($request){$row=Db::one('SELECT * FROM documents WHERE id=?',[$request['id']]);DocumentService::update((int)$row['id'],$request['title'],$row['category'],$row['notes']);return ['ok'=>true];})(),
 'revoke'=>(function()use($request){Db::run('UPDATE platform_peers SET revoked=1 WHERE id=?',[$request['peer']]);return ['ok'=>true];})(),
 'list'=>['tasks'=>Db::all('SELECT id,title,due,status FROM tasks'),'contacts'=>LifePlanningService::rows('contacts'),'documents'=>array_map(function($r){$r['actual_sha256']=hash_file('sha256',DocumentService::path($r,DATA));return $r;},Db::all('SELECT * FROM documents WHERE deleted_at IS NULL'))],
 default=>throw new InvalidArgumentException('Unknown fixture operation.')};echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(InvalidArgumentException $e){echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR);}finally{Db::close();}
