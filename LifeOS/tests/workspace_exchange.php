<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php'; require_once ROOT.'/app/Core/Backup.php';
$checks=0;
function wxcheck(bool $ok,string $label):void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function wxreject(callable $fn,string $label):void { try { $fn(); } catch(InvalidArgumentException|JsonException $e) { wxcheck(true,$label); return; } throw new RuntimeException('Expected rejection: '.$label); }
$base=sys_get_temp_dir().'/lifeos-workspace-exchange-'.bin2hex(random_bytes(8)); mkdir($base,0700); mkdir($base.'/data',0700); mkdir($base.'/shared',0700); define('DATA',$base.'/data');
$dirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',Modules::discover(ROOT.'/app/Modules')));
function wxinit(array $dirs):void { Db::init(DATA.'/lifeos.db'); Migrator::run($dirs); Db::set('onboarding_complete','1'); }
try {
    wxinit($dirs); $a=DeviceSyncService::identity(); $bpair=sodium_crypto_box_keypair(); $bpub=base64_encode(sodium_crypto_box_publickey($bpair)); $bid=hash('sha256',sodium_crypto_box_publickey($bpair));
    DeviceSyncService::pair('Computer B',$bpub);
    Db::set('pin_hash','SOURCE-PIN'); Db::set('connection_secret_fixture','SOURCE-SECRET'); Db::set('account_password','SOURCE-PASSWORD');
    Db::run("INSERT INTO tasks(title,due,source) VALUES('Snapshot source task','2099-12-01','planner')");
    Db::run("INSERT INTO knowledge_notes(title,body) VALUES('Snapshot private note','Explicitly shared source note')");
    $text=$base.'/source.txt';file_put_contents($text,'Source document bytes');DocumentService::store($text,'source.txt','Source document','Other','Source notes',DATA);
    $config=['enabled'=>1,'folder'=>$base.'/shared','peer'=>$bid,'minutes'=>15,'share_workspace'=>'yes','fingerprint'=>$bid];
    $bad=$config;$bad['fingerprint']='bad';wxreject(fn()=>WorkspaceExchange::configure($bad),'Different fingerprint refused');
    $bad=$config;$bad['share_workspace']='';wxreject(fn()=>WorkspaceExchange::configure($bad),'Explicit private record sharing required');
    $bad=$config;$bad['folder']=DATA;wxreject(fn()=>WorkspaceExchange::configure($bad),'Profile-internal transport refused');
    WorkspaceExchange::configure($config);$sourceRevision=WorkspaceExchange::revision();$name=WorkspaceExchange::send();$path=$base.'/shared/'.$name;
    wxcheck(is_file($path)&&!str_contains(file_get_contents($path),'Snapshot source task'),'Automatic transport contains ciphertext, not records');
    wxcheck(WorkspaceExchange::revision()===$sourceRevision,'Export leaves source records unchanged');
    Db::close();rename(DATA.'/lifeos.db',$base.'/a.db');rename(DATA.'/uploads',$base.'/a-uploads');
    foreach(['-wal','-shm']as $tail)if(is_file(DATA.'/lifeos.db'.$tail))unlink(DATA.'/lifeos.db'.$tail);
    file_put_contents(DATA.'/.platform-device-key',$bpair); wxinit($dirs); DeviceSyncService::pair('Computer A',$a['public']);
    Db::set('pin_hash','LOCAL-PIN');Db::set('connection_secret_fixture','LOCAL-SECRET');Db::set('account_password','LOCAL-PASSWORD');
    Db::run("INSERT INTO tasks(title,source) VALUES('Local task being replaced','planner')");
    $apiToken=str_repeat('a',64);$apiHash=hash('sha256',$apiToken);Db::run("INSERT INTO connectivity_tokens(name,hash,scopes,expires_at) VALUES('Local token',?,'[\"tasks.write\"]','2099-12-01')",[$apiHash]);
    Db::run("INSERT INTO connectivity_macros(name,actions,enabled) VALUES('Local approved action','[]',1)");
    Db::run("INSERT INTO automation_rules(name,enabled,trigger,conditions,actions) VALUES('Local rule',1,'daily','{}','[]')");
    Db::set('planning_enabled','1');Db::set('planning_reschedule','1');Db::set('uni_calendar_enabled','1');Db::set('connections_config',json_encode(['calendar_enabled'=>true,'calendar_push'=>true,'mail_enabled'=>true]));
    $text=$base.'/local.txt';file_put_contents($text,'Local document bytes');DocumentService::store($text,'local.txt','Local document','Other','',DATA);
    WorkspaceExchange::configure(['enabled'=>1,'folder'=>$base.'/shared','peer'=>$a['id'],'minutes'=>15,'share_workspace'=>'yes','fingerprint'=>$a['id']]);
    wxcheck(WorkspaceExchange::incoming()[0]['file']===$name,'Paired incoming snapshot discovered automatically');
    $renamed=preg_replace('/-[a-f0-9]{32}\.lifeosws$/','-'.str_repeat('0',32).'.lifeosws',$name);copy($path,$base.'/shared/'.$renamed);wxreject(fn()=>WorkspaceExchange::preview($renamed),'Renamed snapshot cannot misrepresent authenticated header');unlink($base.'/shared/'.$renamed);
    $review=WorkspaceExchange::preview($name);$tasks=array_values(array_filter($review['changes'],fn($r)=>$r['table']==='tasks'))[0];wxcheck($tasks['changed']===1,'Review counts replacement of an existing record');
    wxcheck($review['sender_name']==='Computer A'&&$review['from']===$a['id'],'Review identifies authenticated sender and fingerprint');
    wxcheck(!array_filter($review['changes'],fn($r)=>$r['table']==='settings'),'Local credentials excluded from record review');
    wxreject(fn()=>WorkspaceExchange::apply($review,'yes'),'Exact replacement confirmation required');
    Db::run("INSERT INTO tasks(title,source) VALUES('New local edit after review','planner')");wxreject(fn()=>WorkspaceExchange::apply($review,'REPLACE'),'Stale review cannot discard newer local records');
    $review=WorkspaceExchange::preview($name);
    // Exercise the actual restore staging contract, then interrupt it before
    // activation. Invalidation and replay protection must already be in the
    // staged database, with no change to the still-active local workspace.
    $probeGeneration=str_repeat('f',64);$probeHash=hash('sha256','staging-interruption');
    $probeArchive=EncryptedBackupService::create(DATA,'example-staging-passphrase');
    $prepare=new ReflectionMethod(WorkspaceExchange::class,'prepareReplacement');
    $liveRevision=WorkspaceExchange::revision();
    try {
        EncryptedBackupService::restore($probeArchive,'example-staging-passphrase',DATA,$dirs,function($file)use($prepare,$probeGeneration,$probeHash,$a){
            $prepare->invoke(null,$file,$probeHash,$a['id'],$probeGeneration);
            $staged=new PDO('sqlite:'.$file);$generation=$staged->query("SELECT value FROM settings WHERE key='workspace_generation'")->fetchColumn();$receipt=$staged->query("SELECT peer FROM platform_workspace_receipts WHERE hash='".$probeHash."'")->fetchColumn();
            wxcheck($generation===$probeGeneration&&$receipt===$a['id'],'Session invalidation and replay receipt exist in staged database before activation');
            throw new RuntimeException('Simulated interruption after preparation, before activation');
        });
        throw new RuntimeException('Expected simulated interruption');
    } catch(RuntimeException $error) {
        if($error->getMessage()!=='Simulated interruption after preparation, before activation')throw $error;
    } finally {unlink($probeArchive);}
    wxcheck(WorkspaceExchange::revision()===$liveRevision&&Db::setting('workspace_generation')===''&&Db::one('SELECT hash FROM platform_workspace_receipts WHERE hash=?',[$probeHash])===null,'Interrupted staging keeps live records, session generation and receipts unchanged');
    Db::run('UPDATE platform_peers SET revoked=1 WHERE id=?',[$a['id']]);wxreject(fn()=>WorkspaceExchange::apply($review,'REPLACE'),'Revoked peer cannot replace workspace');Db::run('UPDATE platform_peers SET revoked=0 WHERE id=?',[$a['id']]);
    $raw=file_get_contents($path);$offset=strlen($raw)-20;$changed=$raw;$changed[$offset]=chr(ord($changed[$offset])^1);file_put_contents($path,$changed);wxreject(fn()=>WorkspaceExchange::preview($name),'Tampered ciphertext rejected before writes');file_put_contents($path,$raw);
    $safety=WorkspaceExchange::apply($review,'REPLACE');
    wxcheck(preg_match('/^[a-f0-9]{64}$/D',Db::setting('workspace_generation'))===1&&Db::one('SELECT peer FROM platform_workspace_receipts WHERE hash=?',[$review['hash']])['peer']===$a['id'],'Activated records include session invalidation and replay receipt as one restored database');
    wxcheck(Db::one('SELECT title FROM tasks')['title']==='Snapshot source task'&&count(Db::all('SELECT * FROM tasks'))===1,'Reviewed source records replace local records atomically');
    wxcheck(Db::one('SELECT body FROM knowledge_notes')['body']==='Explicitly shared source note','Whole-workspace note records transferred');
    wxcheck(Db::setting('pin_hash')==='LOCAL-PIN'&&Db::setting('connection_secret_fixture')==='LOCAL-SECRET'&&Db::setting('account_password')==='LOCAL-PASSWORD','Source credentials never overwrite local credentials');
    wxcheck(Db::one('SELECT hash FROM connectivity_tokens')['hash']===$apiHash,'Local API token remains local, never replaced with a source credential');
    $_SERVER['REMOTE_ADDR']='127.0.0.1';$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$apiToken;
    wxreject(fn()=>ConnectivityService::api('POST','/api/v1/tasks/1/complete'),'Previously authorized API client cannot act on a replaced numeric task identity');
    wxcheck(Db::one('SELECT status FROM tasks WHERE id=1')['status']==='open','Revoked stale API write leaves incoming task untouched');
    unset($_SERVER['HTTP_AUTHORIZATION'],$_SERVER['REMOTE_ADDR']);
    wxcheck(DeviceSyncService::identity()['id']===$bid&&Db::one('SELECT id FROM platform_peers')['id']===$a['id'],'Device keys and pairings retained locally');
    wxcheck((int)Db::one('SELECT enabled FROM connectivity_macros')['enabled']===0&&(int)Db::one('SELECT enabled FROM automation_rules')['enabled']===0&&Db::setting('planning_enabled')==='0'&&!ConnectivityService::config()['calendar_enabled']&&!WorkspaceExchange::config()['enabled'],'Approved jobs paused before they can act on replaced numeric record identities or forward newly received records');
    wxcheck(Db::setting('uni_calendar_enabled')==='0','University calendar background imports require renewed approval after workspace replacement');
    $doc=Db::one('SELECT * FROM documents');wxcheck(file_get_contents(DocumentService::path($doc,DATA))==='Source document bytes','Source uploaded file verified and restored');
    wxcheck(is_file(DATA.'/backups/'.$safety.'/database.db')&&is_dir(DATA.'/backups/'.$safety.'/uploads'),'Previous local database and documents retained for recovery');
    wxreject(fn()=>WorkspaceExchange::preview($name),'Replayed replacement refused');
    WorkspaceExchange::configure(['enabled'=>1,'folder'=>$base.'/shared','peer'=>$a['id'],'minutes'=>15,'share_workspace'=>'yes','fingerprint'=>$a['id']]);
    Db::set('workspace_exchange_attempt','0');$tick=WorkspaceExchange::tick();wxcheck($tick['ran']===1&&$tick['failed']===0,'Resident tick automatically delivers changed workspace after deliberate sharing approval');
    Db::set('workspace_exchange_attempt','0');wxcheck(WorkspaceExchange::tick()['ran']===0,'Unchanged workspace does not generate repeated snapshots');
    WorkspaceExchange::configure(['enabled'=>0]);Db::run("INSERT INTO tasks(title) VALUES('After sharing paused')");Db::set('workspace_exchange_attempt','0');wxcheck(WorkspaceExchange::tick()['ran']===0,'Paused delivery never shares new records');
    wxcheck(Db::one('PRAGMA integrity_check')['integrity_check']==='ok'&&Db::all('PRAGMA foreign_key_check')===[],'Database and relationships valid after replacement');
} finally {
    Db::close();$root=realpath($base);if(!$root||!str_contains($root,'lifeos-workspace-exchange-'))throw new RuntimeException('Unsafe cleanup root');
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $item){if($item->isDir()&&!$item->isLink())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($root);
}
echo "$checks workspace exchange checks passed.\n";
