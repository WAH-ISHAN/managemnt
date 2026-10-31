<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';require ROOT.'/app/Core/Backup.php';
$path=sys_get_temp_dir().'/lifeos-studio-'.bin2hex(random_bytes(8));mkdir($path);define('DATA',$path);
$checks=0;
function studio_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function studio_reject(callable $fn,string $label):void{try{$fn();}catch(InvalidArgumentException){studio_check(true,$label);return;}throw new RuntimeException($label);}
try{
    Db::init($path.'/lifeos.db');$mods=Modules::discover(ROOT.'/app/Modules');$dirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$mods));Migrator::run($dirs);
    $site=WebsiteService::save(null,['name'=>'Studio A','base_url'=>'https://studio-a.example.com']);$other=WebsiteService::save(null,['name'=>'Studio B','base_url'=>'https://studio-b.example.com']);
    studio_check(WebAdminService::summary($site)['content']===0 && !WebAdminService::activity($site),'New studio is genuinely empty');
    foreach(WebAdminService::sections() as $section=>$schema){
        $fields=[];foreach($schema['fields'] as $name=>$field)if($field['required'])$fields[$name]=match($field['type']){'money'=>'123.45','slug'=>'first-page',default=>'Required test value'};
        $id=WebAdminService::save($site,$section,['title'=>'Test '.$section,'status'=>array_key_first($schema['statuses']),'fields'=>$fields]);
        studio_check(WebAdminService::record($site,$id)['section']===$section,'Every combined-panel section saves real records');
    }
    studio_check(array_sum(WebAdminService::summary($site)['counts'])===17,'Dashboard totals reflect all seventeen sections');
    studio_check(array_sum(WebAdminService::summary($other)['counts'])===0,'Other website remains empty');
    $page=WebAdminService::listing($site,'pages')['rows'][0];
    studio_reject(fn()=>WebAdminService::record($other,(int)$page['id']),'Cross-website record read denied');
    studio_reject(fn()=>WebAdminService::save($other,'pages',['title'=>'Bad','status'=>'draft','fields'=>['slug'=>'page']],(int)$page['id'],1),'Cross-website update denied');
    studio_reject(fn()=>WebAdminService::trash($other,(int)$page['id'],1),'Cross-website delete denied');
    WebAdminService::save($site,'pages',['title'=>'Updated page','status'=>'ready','fields'=>['slug'=>'updated-page','body'=>'Keep <b>markup</b> as content']],(int)$page['id'],1);
    studio_check(WebAdminService::record($site,(int)$page['id'])['version']===2,'Optimistic update increments version');
    studio_reject(fn()=>WebAdminService::save($site,'pages',['title'=>'Stale','status'=>'draft','fields'=>['slug'=>'stale']],(int)$page['id'],1),'Stale edit rejected');
    studio_reject(fn()=>WebAdminService::trash($site,(int)$page['id'],1),'Stale delete rejected');
    studio_check(WebAdminService::listing($site,'pages','Updated','ready')['count']===1,'Search and status filters combine');
    studio_check(WebAdminService::listing($site,'pages','%')['count']===0,'Search is literal rather than wildcard SQL');
    studio_reject(fn()=>WebAdminService::save($site,'pages',['title'=>'Duplicate','status'=>'draft','fields'=>['slug'=>'updated-page']]),'Duplicate active slug rejected');
    WebAdminService::trash($site,(int)$page['id'],2);studio_check(WebAdminService::listing($site,'pages')['count']===0 && count(WebAdminService::deleted($site))===1,'Trash removes active record while retaining restore history');
    $duplicate=WebAdminService::save($site,'pages',['title'=>'Replacement','status'=>'draft','fields'=>['slug'=>'updated-page']]);
    studio_reject(fn()=>WebAdminService::trash($site,(int)$page['id'],3,true),'Restore preserves active slug uniqueness');
    WebAdminService::trash($site,$duplicate,1);WebAdminService::trash($site,(int)$page['id'],3,true);
    studio_check(WebAdminService::record($site,(int)$page['id'])['version']===4,'Restore advances record version');
    foreach([
        ['pages',['slug'=>'bad/slug']],['branding',['accent'=>'javascript:bad']],['projects',['link'=>'javascript:alert(1)']],['customers',['email'=>'bad-email']],['tasks',['due_date'=>'2026-02-31']],['invoices',['reference'=>'INV-X','customer'=>'Customer','amount'=>'1.001']],['services',['price'=>'-1']],['projects',['unknown'=>'field']],
    ] as [$section,$fields])studio_reject(fn()=>WebAdminService::save($site,$section,['title'=>'Bad record','status'=>array_key_first(WebAdminService::definition($section)['statuses']),'fields'=>$fields]),'Malformed fields rejected');
    studio_reject(fn()=>WebAdminService::save($site,'pages',['title'=>['bad'],'status'=>'draft','fields'=>['slug'=>'bad']]),'Malformed title rejected');
    studio_reject(fn()=>WebAdminService::listing($site,'pages','','invented'),'Unknown status rejected');
    studio_reject(fn()=>WebAdminService::definition('unknown'),'Unknown panel section rejected');
    $invoice=WebAdminService::save($site,'invoices',['title'=>'Invoice','status'=>'sent','fields'=>['reference'=>'INV-001','customer'=>'A','amount'=>'555.55']]);
    studio_check(WebAdminService::summary($site)['due']===555.55,'Invoice balance uses exact cents');
    WebAdminService::save($site,'invoices',['title'=>'Invoice','status'=>'paid','fields'=>['reference'=>'INV-001','customer'=>'A','amount'=>'555.55']],$invoice,1);
    studio_check(WebAdminService::summary($site)['paid']===555.55 && WebAdminService::summary($site)['due']===0,'Paid invoices leave outstanding balance');
    studio_check((int)Db::one('SELECT COUNT(*) n FROM expenses')['n']===0,'Studio invoices never create unintended Money transactions');
    studio_reject(fn()=>WebsiteService::remove($site),'Website deletion preserves Studio records');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lS8AAAAASUVORK5CYII=');$storage=str_repeat('a',48).'.bin';$file=WebAdminService::mediaPath(['storage_name'=>$storage]);mkdir(dirname($file),0700,true);file_put_contents($file,$png);$info=WebAdminService::inspectMedia($file);
    Db::run('INSERT INTO website_studio_media(website_id,filename,storage_name,mime,bytes,sha256) VALUES (?,?,?,?,?,?)',[$site,'asset.png',$storage,$info['mime'],$info['bytes'],$info['sha256']]);
    $backup=EncryptedBackupService::create($path,'Studio backup passphrase 987!');
    studio_check(is_file($backup),'Encrypted backup supports actual Studio media');
    file_put_contents($file,'changed');
    studio_reject(fn()=>EncryptedBackupService::create($path,'Studio backup passphrase 987!'),'Changed Studio media rejected by backup integrity verification');
    EncryptedBackupService::restore($backup,'Studio backup passphrase 987!',$path,$dirs);
    studio_check(file_get_contents($file)===$png && WebAdminService::summary($site)['media']===1,'Encrypted restore recovers Studio database and original media together');
    $export=WebAdminService::export($site);studio_check($export['format']==='lifeos-admin-studio-v1' && count($export['media'])===1,'Workspace export includes real record and media metadata');
    echo "$checks combined Admin Studio service checks passed.\n";
}finally{Db::close();$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($iterator as $item)$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());rmdir($path);}
