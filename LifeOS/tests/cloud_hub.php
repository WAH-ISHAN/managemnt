<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require ROOT.'/integrations/website-gateway/src/Hub.php';
$folder=sys_get_temp_dir().'/lifeos-hub-'.bin2hex(random_bytes(8));mkdir($folder);$operator=bin2hex(random_bytes(32));$hub=new LifeOSHub($folder.'/gateway.db',random_bytes(32),'https://hub.example.com/index.php',$operator);$checks=0;
function hub_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function hub_denied(callable $fn,int $status,string $label):void{try{$fn();}catch(LifeOSConnectorError $e){hub_check($e->status===$status,$label);return;}throw new RuntimeException($label);}
try{
    $call=fn($method,$path,$input=[],$token=null)=>$hub->handle($method,'/api/v1'.$path,$token??$operator,$input,'192.0.2.5');
    $client=$call('POST','/clients',['name'=>'First real test tenant'])['client'];$otherClient=$call('POST','/clients',['name'=>'Other tenant'])['client'];
    $created=$call('POST','/sites',['client_id'=>$client['id'],'name'=>'Owned reference website']);$id=$created['site']['id'];$siteToken=$created['site_token'];$secret=$created['webhook_secret'];
    $other=$call('POST','/sites',['client_id'=>$otherClient['id'],'name'=>'Other site','url'=>'https://other.example.com']);
    hub_check(str_starts_with($created['reference_url'],'https://hub.example.com/index.php/site/'),'Reference website is a real gateway-rendered URL');
    $manifest=['pages'=>[['slug'=>'home','fields'=>[['key'=>'hero_title','type'=>'text','required'=>true],['key'=>'body','type'=>'richtext'],['key'=>'image','type'=>'image'],['key'=>'score','type'=>'number'],['key'=>'visible','type'=>'boolean']]]]];
    $call('POST','/sites/'.$id.'/register',['manifest'=>$manifest,'initial'=>['home'=>['hero_title'=>'First title','body'=>'Original copy']]],$siteToken);
    hub_check(count($call('GET','/sites/'.$id.'/manifest',[],$siteToken)['pages'])===1,'Manifest registered through the site connector token');
    hub_denied(fn()=>$call('GET','/sites/'.$id.'/pages/home',[],$siteToken),404,'Unpublished drafts are invisible to site tokens');
    $user=$call('POST','/users',['client_id'=>$client['id'],'username'=>'editor','role'=>'editor','sites'=>[$id]]);
    $login=$hub->handle('POST','/api/v1/login',null,['username'=>'editor','password'=>$user['password']],'192.0.2.5');$token=$login['token'];
    $overview=$call('GET','/overview',[],$token);hub_check(count($overview['sites'])===1&&$overview['clients']===[]&&$overview['users']===[],'Client discovery is tenant-scoped and omits provider management data');
    hub_denied(fn()=>$call('GET','/sites/'.$other['site']['id'].'/manifest',[],$token),403,'Guessed other-tenant site denied');
    hub_denied(fn()=>$call('POST','/clients',['name'=>'Forbidden'],$token),403,'Client cannot create tenants');
    hub_denied(fn()=>$call('POST','/sites/'.$id.'/register',['manifest'=>$manifest],$token),403,'Editor cannot replace schemas');
    $fields=['hero_title'=>'Edited draft','body'=>'A real saved draft','image'=>'','score'=>null,'visible'=>false];
    $draft=$call('PUT','/sites/'.$id.'/pages/home',['version'=>0,'fields'=>$fields],$token);hub_check($draft['version']===1&&$draft['status']==='draft','Editor saves a real versioned draft');
    hub_denied(fn()=>$call('PUT','/sites/'.$id.'/pages/home',['version'=>0,'fields'=>$fields],$token),409,'Concurrent stale draft rejected');
    hub_denied(fn()=>$call('POST','/sites/'.$id.'/pages/home/publish',['version'=>1],$token),403,'Edit-only client cannot publish');
    hub_check($hub->publicSite($id)['pages']===[],'Saved draft does not appear on the public website');
    $publish=$call('POST','/sites/'.$id.'/pages/home/publish',['version'=>1]);hub_check($publish['deployment_status']==='queued','Publishing creates a genuine queued delivery instead of claiming live');
    $page=$call('GET','/sites/'.$id.'/pages/home',[],$siteToken);hub_check($page['fields']['hero_title']==='Edited draft'&&$page['version']===1,'Connector reads the published version');
    hub_check($hub->publicSite($id)['pages'][0]['fields']['hero_title']==='Edited draft','Public reference website uses published content');
    hub_denied(fn()=>$call('GET','/sites/'.$id.'/pages/home/preview',[],$siteToken),403,'Site key cannot preview drafts');
    $calls=[];
    LifeOSHubHttp::testTransport(function($url,$method,$body,$headers)use(&$calls){$calls[]=[$url,$method,$body,$headers];return ['status'=>200,'body'=>'<meta content="lifeos-version:home:1">','latency_ms'=>34,'ssl_expires'=>'2027-01-01T00:00:00+00:00'];});
    hub_check($hub->work()===1,'Durable publish worker processes the queued delivery');
    $jobs=$call('GET','/sites/'.$id.'/deployments')['deployments'];hub_check($jobs[0]['status']==='live','Only a real matching rendered-version marker marks a site live');
    $health=$call('POST','/sites/'.$id.'/health')['health'];hub_check((int)$health['http_status']===200&&(int)$health['latency_ms']===34&&$health['ssl_expires']!==null,'Site health stores actual HTTP latency and certificate metadata');
    hub_check($call('GET','/sites/'.$id.'/health')['uptime_percent']===100.0,'Uptime is computed from recorded samples');
    $claim=['deployment_id'=>$publish['deployment_id']];hub_check($call('POST','/sites/'.$id.'/webhooks/claim',$claim,$siteToken)['claimed']===true,'Persistent replay ledger claims first delivery');hub_check($call('POST','/sites/'.$id.'/webhooks/claim',$claim,$siteToken)['claimed']===false,'Duplicate delivery is detected across requests');
    hub_denied(fn()=>$call('POST','/sites/'.$id.'/webhooks/claim',$claim,$token),403,'Client editor cannot act as site connector');
    $fields['hero_title']='Second title';$call('PUT','/sites/'.$id.'/pages/home',['version'=>1,'fields'=>$fields],$token);$second=$call('POST','/sites/'.$id.'/pages/home/publish',['version'=>2]);
    LifeOSHubHttp::testTransport(fn()=>['status'=>200,'body'=>'Old version without expected marker','latency_ms'=>20,'ssl_expires'=>null]);$hub->work();$jobs=$call('GET','/sites/'.$id.'/deployments')['deployments'];$job=array_values(array_filter($jobs,fn($j)=>$j['id']===$second['deployment_id']))[0];hub_check($job['status']==='building','HTTP success alone does not prove the new version is live');
    $rollback=$call('POST','/sites/'.$id.'/pages/home/rollback',['version'=>2,'target_version'=>1]);hub_check($rollback['version']===3&&$call('GET','/sites/'.$id.'/pages/home',[],$siteToken)['fields']['hero_title']==='Edited draft','Rollback creates a new version from a real prior publication');
    hub_check(count($call('GET','/sites/'.$id.'/pages/home/versions')['versions'])===5,'Draft and published version history retained');
    hub_check(count($call('GET','/sites/'.$id.'/audit')['audit'])>=5,'Changes have a real actor and site audit log');
    hub_denied(fn()=>$call('GET','/sites/'.$id.'/audit',[],$siteToken),403,'Site read token cannot obtain staff audit data');
    $manifest['pages'][0]['fields'][]=['key'=>'new_field','type'=>'text'];hub_denied(fn()=>$call('POST','/sites/'.$id.'/register',['manifest'=>$manifest],$siteToken),409,'Unreviewed destructive schema change is blocked');
    hub_denied(fn()=>$call('PUT','/sites/'.$id.'/pages/home',['version'=>3,'fields'=>[...$fields,'unknown'=>'field']],$token),422,'Unknown page fields rejected');
    hub_denied(fn()=>$call('POST','/users',['client_id'=>$client['id'],'username'=>'wrong','sites'=>[$other['site']['id']]]),422,'Provider cannot accidentally mix client sites in a client account');
    $pdo=new PDO('sqlite:'.$folder.'/gateway.db');hub_check($pdo->query('SELECT secret FROM hub_sites WHERE id='.$pdo->quote($id))->fetchColumn()!==$secret,'Webhook secrets encrypted at rest');
    $call('POST','/users/disable',['username'=>'editor']);hub_denied(fn()=>$call('GET','/overview',[],$token),401,'Disabling client revokes existing sessions immediately');
    $hookSite=$call('POST','/sites',['client_id'=>$client['id'],'name'=>'Webhook site','url'=>'https://render.example.com','webhook_url'=>'https://render.example.com/lifeos/webhook']);$sid=$hookSite['site']['id'];$simple=['pages'=>[['slug'=>'home','fields'=>[['key'=>'title','type'=>'text','required'=>true]]]]];$call('POST','/sites/'.$sid.'/register',['manifest'=>$simple,'initial'=>['home'=>['title'=>'Hooked']]],$hookSite['site_token']);$hookJob=$call('POST','/sites/'.$sid.'/pages/home/publish',['version'=>0]);
    $signed=false;LifeOSHubHttp::testTransport(function($url,$method,$body,$headers)use(&$signed,$hookSite){if($method==='POST'){$h=[];foreach($headers as $line){[$k,$v]=explode(': ',$line,2);$h[$k]=$v;}$signed=hash_equals('sha256='.hash_hmac('sha256',$h['X-LifeOS-Timestamp'].'.'.$body,$hookSite['webhook_secret']),$h['X-LifeOS-Signature']);return ['status'=>200,'body'=>'{}','latency_ms'=>1,'ssl_expires'=>null];}return ['status'=>200,'body'=>'lifeos-version:home:0','latency_ms'=>1,'ssl_expires'=>null];});$hub->work();hub_check($signed,'Gateway signs raw webhook body and timestamp with the per-site secret');
    echo "$checks cloud Hub authorization, publishing, versioning and delivery checks passed.\n";
}finally{LifeOSHubHttp::testTransport(null);$call=null;$hub=null;$pdo=null;gc_collect_cycles();foreach(glob($folder.'/*') ?: [] as $file)if(is_file($file))unlink($file);rmdir($folder);}
