<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require ROOT.'/integrations/website-connector/src/Connector.php';
$checks=0;
function account_check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function account_rejects(callable $fn,string $message): void {
    try { $fn(); } catch (InvalidArgumentException|LifeOSConnectorError) { account_check(true,$message); return; }
    throw new RuntimeException($message);
}
$directory=sys_get_temp_dir().'/lifeos-accounts-'.bin2hex(random_bytes(8)); mkdir($directory);
$previousKey=getenv('LIFEOS_WEBSITE_KEY'); putenv('LIFEOS_WEBSITE_KEY='.base64_encode(random_bytes(32))); $_SESSION=[];
$sites=[]; $passwords=[]; $ids=[];
try {
    $upgrade=new PDO('sqlite:'.$directory.'/upgrade.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $upgrade->exec(file_get_contents(ROOT.'/app/Modules/Websites/migrations/001_create.sql'));
    $upgrade->exec("INSERT INTO website_profiles(name,base_url) VALUES ('Existing website','https://existing.example.com')");
    $upgrade->exec(file_get_contents(ROOT.'/app/Modules/Websites/migrations/002_business_groups.sql'));
    account_check($upgrade->query('SELECT business_name FROM website_profiles')->fetchColumn()==='' && $upgrade->query('SELECT name FROM website_profiles')->fetchColumn()==='Existing website','Business grouping migration preserves existing website profiles');
    $upgrade=null;
    Db::init($directory.'/local.sqlite');
    $modules=Modules::discover(ROOT.'/app/Modules');
    Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules)));
    for ($i=0;$i<10;$i++) {
        $url='https://site'.$i.'.example.com/api/lifeos-admin.php';
        $path=$directory.'/remote'.$i.'.sqlite';
        $sites[$url]=['path'=>$path,'accounts'=>new LifeOSConnectorAccounts($path)];
        $passwords[$i]=$sites[$url]['accounts']->provision('client',$i===0 ? 'viewer' : 'admin',$i===0 ? ['pages'] : ['pages','products']);
        $ids[$i]=WebsiteService::save(null,['name'=>'Website '.$i,'business_name'=>'Business '.$i,'base_url'=>'https://site'.$i.'.example.com','connector_url'=>$url]);
    }
    account_check(count(array_unique(array_column(WebsiteService::profiles(),'business_name')))===10,'One owner can organize ten businesses with independent website connections');
    $firstProfile=WebsiteService::profile($ids[0]); unset($firstProfile['business_name']);
    WebsiteService::save($ids[0],$firstProfile);
    account_check(WebsiteService::profile($ids[0])['business_name']==='Business 0','Updating older profile forms preserves the business association');
    account_rejects(fn()=>WebsiteService::save($ids[0],$firstProfile+['business_name'=>str_repeat('x',121)]),'Business labels enforce their length limit');
    WebsiteHttpClient::setTestTransport(static function(string $url,?string $token,array $payload,array $options) use (&$sites): array {
        $site=$sites[$url]; $accounts=$site['accounts'];
        try {
            if ($payload['action']==='login') $response=$accounts->login($payload,'test-client');
            else {
                $permissions=$accounts->authenticate($token ?? '');
                $request=get_object_vars(json_decode(json_encode($payload,JSON_THROW_ON_ERROR),false,32,JSON_THROW_ON_ERROR));
                $response=$payload['action']==='logout' ? $accounts->logout($token) : (new LifeOSWebsiteConnector($site['path'],$permissions))->dispatch($request);
            }
            return ['status'=>200,'body'=>json_encode($response,JSON_THROW_ON_ERROR)];
        } catch (LifeOSConnectorError $e) { return ['status'=>$e->status,'body'=>json_encode(['protocol'=>'lifeos-admin-v1','error'=>$e->getMessage()])]; }
    },static fn(string $host): array=>['93.184.216.34']);
    foreach ($ids as $i=>$id) WebsiteService::login($id,'client',$passwords[$i]);
    account_check(count(WebsiteService::profiles())===10,'One LifeOS holds all ten websites');
    foreach ($ids as $i=>$id) {
        account_check(WebsiteService::session($id)['username']==='client','Each site retains its own signed-in session');
        WebsiteService::refresh($id,'pages');
        account_check(WebsiteService::cached($id,'pages')['total']===0,'Each website has its own content cache');
        account_check(!str_contains(serialize($_SESSION),$passwords[$i]),'Passwords are never retained in app sessions');
    }
    account_check(count(WebsiteService::session($ids[0])['manifest']['collections'])===1,'Viewer sees only assigned collections');
    account_rejects(fn()=>WebsiteService::saveRecord($ids[0],'pages',['title'=>'Denied','slug'=>'denied']),'Viewer cannot create records');
    WebsiteService::saveRecord($ids[1],'pages',['title'=>'Site one only','slug'=>'site-one','body'=>'','published'=>false,'published_on'=>'']);
    WebsiteService::refresh($ids[1],'pages'); WebsiteService::refresh($ids[2],'pages');
    account_check(WebsiteService::cached($ids[1],'pages')['total']===1 && WebsiteService::cached($ids[2],'pages')['total']===0,'Saving on one website never changes another website');
    $activity=WebsiteService::activity();
    account_check(count($activity)===20 && $activity[0]['website_name']==='Website 2' && $activity[0]['business_name']==='Business 2','Unified activity is bounded and identifies the website and business');
    account_check(!str_contains(json_encode($activity),$passwords[1]),'Cross-website activity excludes connection secrets');
    WebsiteService::logout($ids[1]);
    account_check(WebsiteService::session($ids[1])===null && WebsiteService::session($ids[2])!==null,'Signing out of one website leaves the others connected');
    $accounts=$sites['https://site0.example.com/api/lifeos-admin.php']['accounts'];
    $login=$accounts->login(['protocol'=>'lifeos-admin-v1','action'=>'login','username'=>'client','password'=>$passwords[0]],'other-client');
    account_rejects(fn()=>$sites['https://site1.example.com/api/lifeos-admin.php']['accounts']->authenticate($login['token']),'Website sessions cannot authenticate against another website');
    $connector=new LifeOSWebsiteConnector($directory.'/remote0.sqlite',$accounts->authenticate($login['token']));
    account_rejects(fn()=>$connector->dispatch(['protocol'=>'lifeos-admin-v1','action'=>'list','collection'=>'products']),'Server denies unassigned collections even for direct API calls');
    account_rejects(fn()=>$connector->dispatch(['protocol'=>'lifeos-admin-v1','action'=>'create','collection'=>'pages','data'=>(object)['title'=>'Denied','slug'=>'denied']]),'Server enforces read-only permissions');
    $newPassword=$accounts->provision('client','editor',['pages']);
    account_rejects(fn()=>$accounts->authenticate($login['token']),'Resetting an account revokes existing remote sessions');
    $login=$accounts->login(['protocol'=>'lifeos-admin-v1','action'=>'login','username'=>'client','password'=>$newPassword],'other-client');
    $permissions=$accounts->authenticate($login['token']);
    account_check($permissions['pages']===['list','create','update'],'Editor permissions omit delete');
    $accounts->disable('client');
    account_rejects(fn()=>$accounts->authenticate($login['token']),'Disabled accounts lose access immediately');
    for ($attempt=0;$attempt<5;$attempt++) account_rejects(fn()=>$accounts->login(['protocol'=>'lifeos-admin-v1','action'=>'login','username'=>'missing','password'=>'wrong'],'limited-client'),'Invalid credentials rejected');
    try { $accounts->login(['protocol'=>'lifeos-admin-v1','action'=>'login','username'=>'missing','password'=>'wrong'],'limited-client'); throw new RuntimeException('Rate limit missing'); }
    catch (LifeOSConnectorError $e) { account_check($e->status===429,'Repeated login failures are rate limited'); }
    WebsiteService::disconnectAll();
    foreach ($ids as $id) account_check(WebsiteService::session($id)===null,'Locking clears all ten website sessions');
    echo $checks." multi-website account checks passed.\n";
} finally {
    Db::close(); WebsiteHttpClient::setTestTransport(null); $_SESSION=[];
    $sites=[]; $accounts=null; $connector=null;
    putenv($previousKey===false ? 'LIFEOS_WEBSITE_KEY' : 'LIFEOS_WEBSITE_KEY='.$previousKey);
    foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
