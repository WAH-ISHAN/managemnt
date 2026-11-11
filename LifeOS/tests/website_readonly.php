<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$checks=0;
function readonly_check(bool $ok,string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function readonly_reject(callable $fn,string $message): void { try { $fn(); } catch (InvalidArgumentException) { readonly_check(true,$message); return; } throw new RuntimeException($message); }
$temporary=sys_get_temp_dir().'/lifeos-readonly-'.bin2hex(random_bytes(8)).'.db';
$previousKey=getenv('LIFEOS_WEBSITE_KEY'); putenv('LIFEOS_WEBSITE_KEY='.base64_encode(random_bytes(32))); $_SESSION=['csrf'=>bin2hex(random_bytes(24))];
$manifest=['protocol'=>'lifeos-admin-v1','site'=>['name'=>'Read-only contract fixture'],'collections'=>[['id'=>'inquiries','label'=>'Inquiries','capabilities'=>['list','create','update'],'fields'=>[
    ['name'=>'customer','label'=>'Customer','type'=>'text','required'=>true,'max_length'=>120,'read_only'=>true],
    ['name'=>'subtotalLkr','label'=>'Amount','type'=>'number','required'=>true,'read_only'=>true],
    ['name'=>'verified','label'=>'Verified','type'=>'boolean','required'=>true,'read_only'=>true],
    ['name'=>'internalNotes','label'=>'Notes','type'=>'textarea','required'=>false,'max_length'=>5000]
]]]];
$remote=['id'=>'inquiry-1','version'=>1,'data'=>['customer'=>'<script>QA customer</script>','subtotalLkr'=>125,'verified'=>true,'internalNotes'=>'Initial notes']]; $writes=[];
WebsiteHttpClient::setTestTransport(static function(string $url,?string $auth,array $payload,array $options) use (&$manifest,&$remote,&$writes): array {
    $reply=static fn(array $body)=>['status'=>200,'body'=>json_encode($body,JSON_THROW_ON_ERROR),'content_type'=>'application/json'];
    if($payload['action']==='manifest')return $reply($manifest);
    if($payload['action']==='list')return $reply(['protocol'=>'lifeos-admin-v1','records'=>[$remote],'total'=>1,'offset'=>$payload['offset'],'limit'=>$payload['limit']]);
    $writes[]=$payload; $remote['data']['internalNotes']=$payload['data']['internalNotes']; $remote['version']++;
    return $reply(['protocol'=>'lifeos-admin-v1','record'=>$remote]);
},static fn(string $host):array=>['93.184.216.34']);
try {
    Db::init($temporary); $modules=Modules::discover(ROOT.'/app/Modules'); Migrator::run(array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$modules)));
    $id=WebsiteService::save(null,['name'=>'Read-only test site','base_url'=>'https://example.com','connector_url'=>'https://example.com/api/lifeos-admin']);
    WebsiteService::connect($id,str_repeat('test-only-',8)); $connection=WebsiteService::session($id); $collection=$connection['manifest']['collections'][0];
    readonly_check($collection['fields'][0]['read_only']===true && $collection['fields'][3]['read_only']===false,'Read-only metadata survives and legacy fields remain editable');
    WebsiteService::refresh($id,'inquiries'); $cached=WebsiteService::cached($id,'inquiries'); $edit=$cached['records'][0];
    readonly_check($edit['data']===$remote['data'],'Readonly text, numeric and boolean customer context survives refresh');
    $profile=WebsiteService::profile($id); $collections=[$collection]; $operations=[]; $costSnapshot=WebsiteCostService::summaries(); $costMonth=date('Y-m'); $costRecords=$costAccounts=$costCategories=[];
    ob_start(); require ROOT.'/app/Modules/Websites/views/show.php'; $websiteHtml=ob_get_clean();
    readonly_check(!str_contains($websiteHtml,'name="fields[customer]"') && !str_contains($websiteHtml,'name="fields[subtotalLkr]"') && !str_contains($websiteHtml,'name="fields[verified]"') && str_contains($websiteHtml,'name="fields[internalNotes]"'),'Website form renders server fields without submitting them');
    readonly_check(str_contains($websiteHtml,'&lt;script&gt;QA customer&lt;/script&gt;') && !str_contains($websiteHtml,'<script>QA customer</script>'),'Readonly form context remains escaped');
    $remoteCollection=$collection; $remoteCache=$cached; $siteId=$id; $studioPath='/web-admin/'.$id;
    ob_start(); require ROOT.'/app/Modules/WebAdmin/views/connected.php'; $studioHtml=ob_get_clean();
    readonly_check(!str_contains($studioHtml,'name="fields[customer]"') && str_contains($studioHtml,'name="fields[internalNotes]"'),'Admin Studio form submits only editable fields');
    WebsiteService::saveRecord($id,'inquiries',['internalNotes'=>'Edited notes'],'inquiry-1',1);
    readonly_check($writes[0]['data']===['internalNotes'=>'Edited notes'],'Update does not send readonly fields');
    WebsiteService::refresh($id,'inquiries');
    readonly_check(WebsiteService::cached($id,'inquiries')['records'][0]['data']['customer']===$remote['data']['customer'],'Readonly context remains after successful save and refresh');
    $before=count($writes); readonly_reject(fn()=>WebsiteService::saveRecord($id,'inquiries',['internalNotes'=>'Forged','customer'=>'Changed'],'inquiry-1',2),'Forged read-only changes are rejected before transport');
    readonly_check(count($writes)===$before,'A rejected readonly edit makes no remote request');
    WebsiteService::saveRecord($id,'inquiries',['internalNotes'=>'Created']);
    readonly_check($writes[1]['data']===['internalNotes'=>'Created'],'Create can omit server-assigned required fields');
    $manifest['collections'][0]['fields'][0]['read_only']='false';
    readonly_reject(fn()=>WebsiteService::connect($id,str_repeat('test-only-',8)),'Non-boolean readonly metadata is rejected');
    $manifest['collections'][0]['fields'][0]['read_only']=true;
    foreach(['customer-email','customer][role','9invalid',str_repeat('a',65)] as $invalid) {
        $manifest['collections'][0]['fields'][0]['name']=$invalid;
        readonly_reject(fn()=>WebsiteService::connect($id,str_repeat('test-only-',8)),'Unsafe field identifiers remain rejected');
    }
    echo "$checks readonly website checks passed.\n";
} finally {
    Db::close(); WebsiteHttpClient::setTestTransport(null); $_SESSION=[]; putenv($previousKey===false?'LIFEOS_WEBSITE_KEY':'LIFEOS_WEBSITE_KEY='.$previousKey);
    foreach([$temporary,$temporary.'-wal',$temporary.'-shm'] as $file)if(is_file($file))unlink($file);
}
