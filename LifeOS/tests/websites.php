<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function website_check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function website_rejects(callable $fn,string $message,?string $contains=null): void {
    try { $fn(); } catch (InvalidArgumentException $error) {
        website_check($contains===null || str_contains($error->getMessage(),$contains),$message.' (error message)'); return;
    }
    throw new RuntimeException($message);
}
set_error_handler(static function (int $severity,string $message,string $file,int $line): never { throw new ErrorException($message,0,$severity,$file,$line); });
$previousKey=getenv('LIFEOS_WEBSITE_KEY');
$key=random_bytes(32); putenv('LIFEOS_WEBSITE_KEY='.base64_encode($key));
$_SESSION=[];
$temporary=sys_get_temp_dir().'/lifeos-websites-'.bin2hex(random_bytes(8)).'.db';
$token='test-only-'.bin2hex(random_bytes(24));
$mode=''; $calls=[]; $records=[]; $serial=0;
$manifest=['protocol'=>'lifeos-admin-v1','site'=>['name'=>'Fictional test website'],'collections'=>[
    ['id'=>'pages','label'=>'Pages','fields'=>[
        ['name'=>'title','label'=>'Title','type'=>'text','required'=>true,'max_length'=>120],
        ['name'=>'body','label'=>'Body','type'=>'textarea','required'=>false,'max_length'=>2000],
        ['name'=>'published','label'=>'Published','type'=>'boolean','required'=>false],
        ['name'=>'price','label'=>'Price','type'=>'number','required'=>false],
        ['name'=>'date','label'=>'Date','type'=>'date','required'=>false]
    ],'capabilities'=>['list','create','update','delete']],
    ['id'=>'readonly','label'=>'Read only','fields'=>[['name'=>'title','label'=>'Title','type'=>'text','required'=>true]],'capabilities'=>['list']]
]];
$dns=['93.184.216.34'];
WebsiteHttpClient::setTestTransport(static function (string $url,string $auth,array $payload,array $options) use (&$calls,&$records,&$serial,&$mode,&$manifest,$token): array {
    $calls[]=['url'=>$url,'payload'=>$payload,'options'=>$options];
    $reply=static fn(array $body,int $status=200)=>['status'=>$status,'body'=>json_encode($body,JSON_THROW_ON_ERROR),'content_type'=>'application/json'];
    if ($mode==='huge') return ['status'=>200,'body'=>str_repeat('x',WebsiteHttpClient::MAX_RESPONSE+1)];
    if ($mode==='html') return ['status'=>200,'body'=>'<html>Not a connector</html>','content_type'=>'text/html'];
    if ($mode==='invalidjson') return ['status'=>200,'body'=>'{broken'];
    if ($mode==='badprotocol') return $reply(['protocol'=>'other']);
    if ($mode==='redirect') return $reply(['protocol'=>'lifeos-admin-v1'],302);
    if ($mode==='forbidden') return $reply(['protocol'=>'lifeos-admin-v1','error'=>'SECRET from server '.$auth],403);
    if ($mode==='failure') return $reply(['protocol'=>'lifeos-admin-v1','error'=>'Internal database password is private'],500);
    if ($mode==='conflict') return $reply(['protocol'=>'lifeos-admin-v1'],409);
    if ($auth!==$token) return $reply(['protocol'=>'lifeos-admin-v1'],401);
    if ($payload['action']==='manifest') return $reply($manifest);
    if ($payload['action']==='list') {
        $rows=array_values($records[$payload['collection']] ?? []);
        return $reply(['protocol'=>'lifeos-admin-v1','records'=>array_slice($rows,$payload['offset'],$payload['limit']),'total'=>count($rows),'offset'=>$payload['offset'],'limit'=>$payload['limit']]);
    }
    if ($payload['action']==='create') {
        $id='record-'.(++$serial); $record=['id'=>$id,'version'=>1,'data'=>$payload['data']];
        $records[$payload['collection']][$id]=$record;
        return $reply(['protocol'=>'lifeos-admin-v1','record'=>$record]);
    }
    $current=$records[$payload['collection']][$payload['id']] ?? null;
    if (!$current || $current['version']!==$payload['version']) return $reply(['protocol'=>'lifeos-admin-v1'],409);
    if ($payload['action']==='update') {
        $record=['id'=>$payload['id'],'version'=>$current['version']+1,'data'=>$payload['data']];
        $records[$payload['collection']][$payload['id']]=$record;
        return $reply(['protocol'=>'lifeos-admin-v1','record'=>$record]);
    }
    if ($payload['action']==='delete') {
        unset($records[$payload['collection']][$payload['id']]);
        return $reply(['protocol'=>'lifeos-admin-v1','deleted'=>true]);
    }
    return $reply(['protocol'=>'lifeos-admin-v1'],400);
},static function (string $host) use (&$dns): array { return $dns; });

try {
    Db::init($temporary);
    $modules=Modules::discover(ROOT.'/app/Modules');
    $dirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($module)=>$module['path'].'/migrations',$modules));
    Migrator::run($dirs); $migrationCount=count(Db::all('SELECT * FROM migrations')); Migrator::run($dirs);
    website_check(count(Db::all('SELECT * FROM migrations'))===$migrationCount,'Website migration is repeatable without duplicate application');
    website_check(WebsiteService::profiles()===[],'A new workspace has no invented website profiles');
    $data=['name'=>'Fictional site','base_url'=>'example.com','connector_url'=>'https://example.com/lifeos-admin.php','server_address'=>'server.example.com','stack'=>'Any backend','notes'=>'Only a test profile'];
    $id=WebsiteService::save(null,$data);
    website_check(WebsiteService::profile($id)['base_url']==='https://example.com','Bare domains normalize to HTTPS');
    website_check(WebsiteHttpClient::normalizeUrl('https://example.com/admin/')==='https://example.com/admin/','A connector endpoint keeps its required trailing slash');
    website_check($calls===[],'Saving a website profile does not make a network request');
    website_check(WebsiteService::profile($id)['last_connected_at']===null && WebsiteService::session($id)===null,'Details alone never claim a successful connection');
    Db::close(); Db::init($temporary);
    website_check(WebsiteService::profile($id)['name']==='Fictional site','Website profiles persist across database reopen');
    foreach (['http://example.com','https://example.com:8443','https://name:password@example.com','https://example.com/?token=secret','https://example.com/#fragment','https://127.0.0.1/api','https://[::1]/api','https://localhost/api','https://host.local/api',"https://example.com/\r\nHeader: bad"] as $invalid) {
        website_rejects(fn()=>WebsiteHttpClient::normalizeUrl($invalid),'Unsafe or ambiguous URL rejected');
    }
    foreach (['127.0.0.1','10.1.2.3','100.64.0.1','169.254.169.254','192.168.1.1','198.18.0.1','203.0.113.1','::1','::ffff:127.0.0.1','fc00::1','2001:db8::1','2002:7f00:1::'] as $invalid) website_check(!WebsiteHttpClient::isPublicIp($invalid),'Private/reserved literal cannot become a connector target');
    website_check(WebsiteHttpClient::isPublicIp('93.184.216.34') && WebsiteHttpClient::isPublicIp('2606:4700:4700::1111'),'Public IPv4 and IPv6 are supported');
    website_rejects(fn()=>WebsiteService::save($id,array_merge($data,['server_address'=>'root:password@server.example.com'])),'Server metadata cannot embed credentials');
    website_rejects(fn()=>WebsiteService::connect($id,'short'),'Short token rejected');
    website_check(WebsiteService::session($id)===null,'A rejected token leaves no connected session');
    WebsiteService::connect($id,$token);
    $session=WebsiteService::session($id);
    website_check($session!==null && $session['manifest']['collections'][0]['id']==='pages','A validated connector manifest generates collection metadata');
    website_check($session['expires_at']>time() && $session['expires_at']<=time()+1800,'Connection has an absolute 30-minute expiry');
    website_check(!str_contains(json_encode($session),$token) && !isset($session['token']),'Public session metadata never returns the connector token');
    website_check(!str_contains(serialize($_SESSION),$token) && !str_contains(serialize($_SESSION),'Fictional test website'),'Token and remote metadata are encrypted in session storage');
    website_check(!str_contains(json_encode(WebsiteService::profiles()).json_encode(WebsiteService::operations($id)),$token),'Token is absent from database profile and audit records');
    website_check(WebsiteService::profile($id)['last_connected_at']!==null,'Only a successful manifest connection records its timestamp');
    $pin=$calls[array_key_last($calls)]['options'];
    website_check($pin['pinnedAddress']==='93.184.216.34' && $pin['verifyPeer'] && $pin['verifyHost']===2 && !$pin['followRedirects'] && $pin['proxy']==='','HTTPS transport pins a checked public address and disables ambient proxy/redirects');
    $dns=['93.184.216.34','127.0.0.1'];
    $before=count($calls);
    website_rejects(fn()=>WebsiteService::refresh($id,'pages'),'Mixed public/private DNS is rejected','reserved');
    website_check(count($calls)===$before,'Private DNS target is blocked before transport'); $dns=['93.184.216.34'];
    WebsiteService::refresh($id,'pages');
    website_check(WebsiteService::cached($id,'pages')['records']===[],'An empty remote collection stays empty');
    $values=['title'=>'<script>test text</script>','body'=>'Only entered data','published'=>'1','price'=>'200.10','date'=>'2026-10-29'];
    WebsiteService::saveRecord($id,'pages',$values);
    website_check(WebsiteService::cached($id,'pages')===null,'A confirmed write invalidates stale cached records');
    website_check(count($records['pages'])===1 && $records['pages']['record-1']['data']['published']===true,'Create sends validated fields to the connector');
    WebsiteService::refresh($id,'pages');
    $cached=WebsiteService::cached($id,'pages');
    website_check($cached['total']===1 && $cached['records'][0]['data']['title']===$values['title'] && $cached['records'][0]['version']===1,'Fetched cache preserves text and optimistic record version');
    website_check(!str_contains(serialize($_SESSION),'Only entered data'),'Remote content is encrypted in the persisted session');
    $count=count($calls);
    website_rejects(fn()=>WebsiteService::saveRecord($id,'pages',array_merge($values,['unexpected'=>'hidden'])),'Unknown fields cannot be sent');
    website_rejects(fn()=>WebsiteService::saveRecord($id,'pages',array_merge($values,['date'=>'2026-02-30'])),'Invalid dates rejected before remote write');
    website_rejects(fn()=>WebsiteService::saveRecord($id,'pages',array_merge($values,['price'=>'NaN'])),'Non-finite numeric text rejected');
    website_rejects(fn()=>WebsiteService::saveRecord($id,'pages',array_merge($values,['published'=>['bad']])),'Nested checkbox values rejected');
    website_rejects(fn()=>WebsiteService::saveRecord($id,'pages',$values,'record-1',null),'Updates require a known version');
    website_rejects(fn()=>WebsiteService::saveRecord($id,'readonly',['title'=>'Forbidden']),'Read-only collections cannot create records');
    website_check(count($calls)===$count,'Invalid writes never reach the connector');
    WebsiteService::saveRecord($id,'pages',array_merge($values,['title'=>'Updated']),'record-1',1);
    website_check($records['pages']['record-1']['version']===2 && $records['pages']['record-1']['data']['title']==='Updated','Updates pass the previous version and use the returned newer version');
    website_rejects(fn()=>WebsiteService::saveRecord($id,'pages',$values,'record-1',1),'Stale updates report conflict','Refresh');
    website_check($records['pages']['record-1']['version']===2,'A conflict does not overwrite the remote record');
    website_rejects(fn()=>WebsiteService::deleteRecord($id,'pages','record-1',1),'Stale deletes report conflict','Refresh');
    website_check(isset($records['pages']['record-1']),'Conflict preserves the remote record');
    WebsiteService::deleteRecord($id,'pages','record-1',2);
    website_check($records['pages']===[],'Confirmed deletion removes only the selected record');
    for ($n=1;$n<=55;$n++) $records['pages']['page-'.$n]=['id'=>'page-'.$n,'version'=>1,'data'=>$values];
    WebsiteService::refresh($id,'pages',50); $page=WebsiteService::cached($id,'pages');
    website_check($page['total']===55 && count($page['records'])===5 && $page['offset']===50 && $page['limit']===50,'Record pages retain total and offset without silently dropping the remainder');
    website_rejects(fn()=>WebsiteService::refresh($id,'pages',-1),'Negative page offset is rejected');
    foreach (['html','invalidjson','badprotocol','redirect','forbidden','failure','huge'] as $failureMode) {
        $mode=$failureMode;
        try { WebsiteService::refresh($id,'pages'); throw new RuntimeException('Expected transport refusal: '.$mode); }
        catch (InvalidArgumentException $error) { website_check(!str_contains($error->getMessage(),$token) && !str_contains($error->getMessage(),'password'),'Remote errors are bounded/redacted and not rendered verbatim'); }
    }
    $mode='';
    $original=$manifest;
    $manifest['collections'][0]['capabilities']=['list',['invalid']];
    website_rejects(fn()=>WebsiteService::connect($id,$token),'Malformed permission arrays are rejected without PHP warnings');
    $manifest=$original; $manifest['collections'][]=$manifest['collections'][0];
    website_rejects(fn()=>WebsiteService::connect($id,$token),'Duplicate collections rejected');
    $manifest=$original; $manifest['collections'][0]['fields'][0]['type']='file';
    website_rejects(fn()=>WebsiteService::connect($id,$token),'Unsupported field types rejected instead of fake controls');
    $manifest=$original; WebsiteService::connect($id,$token);
    $sealed=$_SESSION['website_sessions'][$id]; $_SESSION['website_sessions'][$id]=substr($sealed,0,-4).'AAAA';
    website_check(WebsiteService::session($id)===null,'Tampered encrypted session is discarded');
    WebsiteService::connect($id,$token);
    $raw=base64_decode($_SESSION['website_sessions'][$id]);
    $aad='lifeos-websites:'.$id.':'.WebsiteService::profile($id)['connector_url'];
    $bundle=json_decode(openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$aad),true,32,JSON_THROW_ON_ERROR);
    $bundle['expires_at']=time()-1; $iv=random_bytes(12); $tag='';
    $cipher=openssl_encrypt(json_encode($bundle),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad,16);
    $_SESSION['website_sessions'][$id]=base64_encode($iv.$tag.$cipher);
    website_check(WebsiteService::session($id)===null,'Expired sessions cannot retain website credentials');
    WebsiteService::connect($id,$token); putenv('LIFEOS_WEBSITE_KEY='.base64_encode(random_bytes(32)));
    website_check(WebsiteService::session($id)===null,'A restarted runtime key cannot reopen previous credentials');
    putenv('LIFEOS_WEBSITE_KEY='.base64_encode($key)); WebsiteService::connect($id,$token);
    WebsiteService::save($id,array_merge($data,['name'=>'Renamed profile']));
    website_check(WebsiteService::session($id)===null,'Editing connection details disconnects the old session');
    putenv('LIFEOS_WEBSITE_KEY');
    website_rejects(fn()=>WebsiteService::connect($id,$token),'Missing runtime protection key fails with setup guidance','desktop');
    website_check(WebsiteService::profile($id)['name']==='Renamed profile','Local profile records remain available without a network-session key');
    putenv('LIFEOS_WEBSITE_KEY='.base64_encode($key)); WebsiteService::connect($id,$token); WebsiteService::disconnectAll();
    website_check(WebsiteService::session($id)===null,'Lock/disconnect-all removes all website connections');
    website_check(Db::one('SELECT COUNT(*) n FROM website_operations WHERE website_id=? AND outcome=?',[$id,'failed'])['n']>0,'Local audit records distinguish failed operations without storing content');
    WebsiteService::remove($id);
    website_check(WebsiteService::profiles()===[] && Db::all('SELECT * FROM website_operations')===[],'Removing a local profile deletes only its local audit and session records');
    Db::close(); echo "$checks website manager checks passed.\n";
} finally {
    Db::close(); WebsiteHttpClient::setTestTransport(null); $_SESSION=[];
    putenv($previousKey===false ? 'LIFEOS_WEBSITE_KEY' : 'LIFEOS_WEBSITE_KEY='.$previousKey);
    foreach ([$temporary,$temporary.'-wal',$temporary.'-shm'] as $file) if (is_file($file)) unlink($file);
    restore_error_handler();
}
