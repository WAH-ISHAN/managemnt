<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require ROOT.'/integrations/website-gateway/src/Gateway.php';
$checks=0;
function gateway_check(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException($label);$checks++;}
function gateway_reject(callable $fn,int $status,string $label):void{try{$fn();}catch(LifeOSConnectorError $e){gateway_check($e->status===$status,$label);return;}throw new RuntimeException($label);}
$path=sys_get_temp_dir().'/lifeos-gateway-'.bin2hex(random_bytes(8)).'.db';
$local=sys_get_temp_dir().'/lifeos-gateway-client-'.bin2hex(random_bytes(8)).'.db';
$_SESSION=[];putenv('LIFEOS_WEBSITE_KEY='.base64_encode(random_bytes(32)));
try{
    $url='https://gateway.example.com/index.php';$gateway=new LifeOSGateway($path,random_bytes(32),$url);
    $schema=[['id'=>'pages','label'=>'Pages','capabilities'=>['list','update'],'fields'=>[['name'=>'title','label'=>'Title','type'=>'text','required'=>true,'max_length'=>100]]]];
    $document=['collections'=>['pages'=>[['id'=>'home','version'=>1,'data'=>['title'=>'Original']]]]];
    $permissions=[];
    for($i=1;$i<=10;$i++){$slug='site'.$i;$gateway->provision(['slug'=>$slug,'name'=>'Site '.$i,'base_url'=>'https://site'.$i.'.example.com','business_name'=>'Business '.$i,'mode'=>'content','collections'=>$schema,'document'=>$document]);$permissions[$slug]=['list','update'];}
    $gateway->provision(['slug'=>'private','name'=>'Other client','base_url'=>'https://private.example.com','mode'=>'content','collections'=>$schema,'document'=>$document]);
    $password=$gateway->accounts->provisionSites('client',$permissions);
    $viewerPass=$gateway->accounts->provisionSites('viewer',['site1'=>['list']]);
    $login=fn(string $user,string $pass,string $slug='')=>$gateway->dispatch($slug,null,['protocol'=>'lifeos-admin-v1','action'=>'login','username'=>$user,'password'=>$pass],'192.0.2.1');
    $session=$login('client',$password);$token=$session['token'];
    $call=fn(string $site,string $action,array $more=[])=>$gateway->dispatch($site,$token,['protocol'=>'lifeos-admin-v1','action'=>$action,...$more],'192.0.2.1');
    $catalog=$call('','sites');gateway_check(count($catalog['sites'])===10,'Client sees exactly ten assigned sites');
    gateway_check(!str_contains(json_encode($catalog),'private'),'Other tenant is absent from catalog');
    gateway_check(array_keys($catalog['sites'][0])===['slug','name','base_url','business_name','connector_url'],'Catalog exposes no origin credentials');
    gateway_reject(fn()=>$call('private','manifest'),403,'Guessed site rejected');
    gateway_reject(fn()=>$login('client',$password,'private'),403,'Unassigned per-site login rejected');
    gateway_check($call('site1','manifest')['collections'][0]['capabilities']===['list','update'],'Editor capabilities scoped');
    $viewer=$login('viewer',$viewerPass);$viewToken=$viewer['token'];
    $viewManifest=$gateway->dispatch('site1',$viewToken,['protocol'=>'lifeos-admin-v1','action'=>'manifest'],'192.0.2.1');
    gateway_check($viewManifest['collections'][0]['capabilities']===['list'],'Viewer manifest hides writes');
    $update=['collection'=>'pages','id'=>'home','version'=>1,'data'=>['title'=>'Changed directly']];
    gateway_reject(fn()=>$gateway->dispatch('site1',$viewToken,['protocol'=>'lifeos-admin-v1','action'=>'update',...$update],'192.0.2.1'),403,'Viewer direct write rejected');
    gateway_check($call('site1','update',$update)['record']['version']===2,'Direct persistent save increments record version');
    gateway_check($gateway->publicContent('site1')['collections']['pages'][0]['data']['title']==='Changed directly','Public website reads updated content immediately');
    gateway_check($gateway->publicContent('site2')['collections']['pages'][0]['data']['title']==='Original','Other website content unchanged');
    gateway_reject(fn()=>$call('site1','update',$update),409,'Stale edits conflict');
    gateway_reject(fn()=>$call('site1','update',['collection'=>'pages','id'=>'home','version'=>2,'data'=>['title'=>'Bad','secret'=>'extra']]),422,'Unknown data rejected');
    gateway_check($gateway->publicContent('site1')['collections']['pages'][0]['version']===2,'Failed update does not change stored content');
    gateway_reject(fn()=>$call('site1','delete',['collection'=>'pages','id'=>'home','version'=>2]),403,'Content structure cannot be deleted');
    $fork=$call('site1','site_session');$call('','logout');
    gateway_check($gateway->dispatch('site1',$fork['token'],['protocol'=>'lifeos-admin-v1','action'=>'manifest'],'192.0.2.1')['site']['name']==='Site 1','Independent site session survives parent logout');
    gateway_reject(fn()=>$call('','sites'),401,'Parent session revoked');
    $pdo=new PDO('sqlite:'.$path);gateway_check(!str_contains($pdo->query('SELECT password_hash FROM lifeos_users')->fetchColumn(),$password),'Password stored as hash');
    gateway_check($pdo->query('SELECT COUNT(*) FROM lifeos_sessions WHERE token_hash='.$pdo->quote($fork['token']))->fetchColumn()==0,'Sessions stored hashed');
    $gateway->accounts->disable('client');
    gateway_reject(fn()=>$gateway->dispatch('site1',$fork['token'],['protocol'=>'lifeos-admin-v1','action'=>'manifest'],'192.0.2.1'),401,'Disable revokes site sessions immediately');
    $password=$gateway->accounts->provisionSites('client',$permissions);
    Db::init($local);$modules=Modules::discover(ROOT.'/app/Modules');Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules)));
    $existing=WebsiteService::save(null,['name'=>'Existing local name','base_url'=>'https://site1.example.com','notes'=>'Preserve my notes']);
    WebsiteCostService::add($existing,['date'=>date('Y-m-d'),'kind'=>'hosting','amount'=>'123.45']);
    WebsiteHttpClient::setTestTransport(static function($endpoint,$bearer,$request)use($gateway,$url){
        $slug=$endpoint===$url?'':substr($endpoint,strlen($url.'/sites/'));
        try{$response=$gateway->dispatch($slug,$bearer,$request,'192.0.2.1');return ['status'=>200,'body'=>json_encode($response)];}
        catch(LifeOSConnectorError $e){return ['status'=>$e->status,'body'=>json_encode(['protocol'=>'lifeos-admin-v1','error'=>$e->getMessage()])];}
    },fn()=>['93.184.216.34']);
    gateway_check(WebsiteService::loginGateway($url,'client',$password)===10,'Single LifeOS login connects all ten sites');
    gateway_check(count(WebsiteService::profiles())===10,'Existing matching website reused without duplicates');
    gateway_check(WebsiteService::profile($existing)['notes']==='Preserve my notes' && WebsiteService::profile($existing)['name']==='Existing local name','Existing profile metadata preserved');
    gateway_check(WebsiteCostService::summaries()['total']===123.45,'Maintenance costs preserved on gateway import');
    foreach(WebsiteService::profiles() as $profile)gateway_check(WebsiteService::session((int)$profile['id'])!==null,'Every assigned website connected');
    WebsiteService::logout($existing);
    $other=Db::one('SELECT id FROM website_profiles WHERE id<>? LIMIT 1',[$existing]);
    WebsiteService::refresh((int)$other['id'],'pages',0);
    gateway_check(WebsiteService::cached((int)$other['id'],'pages')['total']===1,'Signing out one website does not disconnect another');
    gateway_check(WebsiteService::loginGateway($url,'client',$password)===10 && count(WebsiteService::profiles())===10,'Repeated gateway login remains idempotent');
    gateway_check(!str_contains(json_encode($_SESSION),$password),'Client password not saved in session');
    $gateway->provision(['slug'=>'proxy','name'=>'Proxy website','base_url'=>'https://origin.example.com','mode'=>'proxy','endpoint'=>'https://origin.example.com/api','token'=>str_repeat('origin-secret',4)]);
    $row=$pdo->query("SELECT secret FROM gateway_sites WHERE slug='proxy'")->fetchColumn();
    gateway_check(!str_contains($row,'origin-secret'),'Origin connector secret encrypted at rest');
    gateway_reject(fn()=>$gateway->publicContent('proxy'),404,'Proxy origin is never publicly exposed');
    $gateway->provision(['slug'=>'wordpress','name'=>'WP client website','base_url'=>'https://wordpress.example.com','mode'=>'proxy','endpoint'=>'https://wordpress.example.com/wp-json/lifeos/v1/admin','username'=>'operator_editor','password'=>'operator-app-password']);
    $wpPass=$gateway->accounts->provisionSites('wpclient',['wordpress'=>['list']]);$wpSession=$login('wpclient',$wpPass);
    $originLogins=0;$originCalls=0;
    WebsiteHttpClient::setTestTransport(static function($endpoint,$bearer,$request)use(&$originLogins,&$originCalls,$schema){
        gateway_check($endpoint==='https://wordpress.example.com/wp-json/lifeos/v1/admin','Origin endpoint stays operator-controlled');
        if($request['action']==='login'){
            $originLogins++;gateway_check($bearer===null && $request['username']==='operator_editor' && $request['password']==='operator-app-password','Gateway authenticates upstream using private adapter account');
            return ['status'=>200,'body'=>json_encode(['protocol'=>'lifeos-admin-v1','token'=>str_repeat('e',64),'expires_at'=>time()+1800])];
        }
        $originCalls++;gateway_check($bearer===str_repeat('e',64),'Origin session used only in gateway-to-site request');
        return ['status'=>200,'body'=>json_encode(['protocol'=>'lifeos-admin-v1','site'=>['name'=>'WP client website'],'collections'=>$schema])];
    },fn()=>['93.184.216.34']);
    for($i=0;$i<2;$i++)$wpResult=$gateway->dispatch('wordpress',$wpSession['token'],['protocol'=>'lifeos-admin-v1','action'=>'manifest'],'192.0.2.1');
    gateway_check($originLogins===1 && $originCalls===2,'Origin session cached instead of logging in for every operation');
    gateway_check($wpResult['collections'][0]['capabilities']===['list'] && !str_contains(json_encode($wpResult),'operator-app-password'),'Client gets its own restricted manifest without origin credentials');
    $pdo->exec("UPDATE gateway_origin_sessions SET expires_at=1 WHERE slug='wordpress'");
    $gateway->dispatch('wordpress',$wpSession['token'],['protocol'=>'lifeos-admin-v1','action'=>'manifest'],'192.0.2.1');
    gateway_check($originLogins===2,'Expired origin sessions renewed server-side');
    $encryptedSession=$pdo->query("SELECT secret FROM gateway_origin_sessions WHERE slug='wordpress'")->fetchColumn();
    gateway_check(!str_contains($encryptedSession,str_repeat('e',64)),'Cached origin session encrypted');
    WebsiteHttpClient::setTestTransport(fn()=>throw new RuntimeException('operator-app-password'),fn()=>['93.184.216.34']);
    try{$gateway->dispatch('wordpress',$wpSession['token'],['protocol'=>'lifeos-admin-v1','action'=>'manifest'],'192.0.2.1');throw new RuntimeException('Expected origin error');}catch(LifeOSConnectorError $e){gateway_check($e->status===502 && !str_contains($e->getMessage(),'operator-app-password'),'Origin failure sanitized before returning to client');}
    $bad=$document;$bad['collections']['credentials']=[];
    try{$gateway->provision(['slug'=>'bad','name'=>'Bad','base_url'=>'https://bad.example.com','mode'=>'content','collections'=>$schema,'document'=>$bad]);throw new RuntimeException('Private extra collection accepted');}catch(InvalidArgumentException){$checks++;}
    echo "$checks gateway and LifeOS integration checks passed.\n";
}finally{WebsiteHttpClient::setTestTransport(null);Db::close();$call=null;$login=null;$gateway=null;$pdo=null;gc_collect_cycles();foreach([$path,$local] as $file)foreach(['','-wal','-shm'] as $suffix)if(is_file($file.$suffix))unlink($file.$suffix);}
