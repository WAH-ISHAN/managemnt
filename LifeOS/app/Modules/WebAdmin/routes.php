<?php
return function(Router $r){
    (require __DIR__.'/cloud-routes.php')($r);
    $r->get('/web-admin',fn()=>View::render(__DIR__.'/views/index.php',['title'=>'Admin Studio','profiles'=>WebsiteService::profiles()]));
    $r->post('/web-admin/workspace',function(){ $id=WebsiteService::save(null,['name'=>text_input('name',120),'base_url'=>text_input('base_url',2048),'business_name'=>text_input('business_name',120,false)]);return redirect('/web-admin/'.$id,'Admin Studio workspace created.'); });
    $r->get('/web-admin/{id}',function($p){
        $id=record_id($p['id']);$profile=WebsiteService::profile($id);$section=$_GET['section'] ?? 'dashboard';$query=$_GET['q'] ?? '';$status=$_GET['status'] ?? '';
        if(!is_string($section) || !is_string($query) || !is_string($status))throw new InvalidArgumentException('Choose valid studio filters.');
        $special=['dashboard','media','activity','trash','settings','access','connected'];$definition=null;$listing=null;$edit=null;$connection=WebsiteService::session($id);$remoteCollection=null;$remoteCache=null;
        if(!in_array($section,$special,true)){
            $definition=WebAdminService::definition($section);$listing=WebAdminService::listing($id,$section,$query,$status,int_range($_GET['page'] ?? '1',1,1000000));
            if(isset($_GET['edit'])){$edit=WebAdminService::record($id,record_id($_GET['edit']));if($edit['section']!==$section || $edit['deleted_at']!==null)throw new InvalidArgumentException('Choose an active record from this section.');}
        }
        if($section==='connected' && $connection){
            $slug=$_GET['collection'] ?? ($connection['manifest']['collections'][0]['id'] ?? '');
            if(!is_string($slug))throw new InvalidArgumentException('Choose a connected website collection.');
            if($slug!==''){$remoteCollection=WebsiteService::collection($id,$slug);$remoteCache=WebsiteService::cached($id,$slug);}
            if(isset($_GET['edit'])){if(!is_string($_GET['edit']))throw new InvalidArgumentException('Choose a remote record.');foreach($remoteCache['records'] ?? [] as $record)if($record['id']===$_GET['edit'])$edit=$record;if(!$edit)throw new InvalidArgumentException('Load the record page before editing.');}
        }
        return View::render(__DIR__.'/views/workspace.php',['title'=>$profile['name'].' · Admin Studio','profile'=>$profile,'profiles'=>WebsiteService::profiles(),'section'=>$section,'definitions'=>WebAdminService::sections(),'definition'=>$definition,'listing'=>$listing,'edit'=>$edit,'summary'=>WebAdminService::summary($id),'connection'=>$connection,'remoteCollection'=>$remoteCollection,'remoteCache'=>$remoteCache]);
    });
    $r->post('/web-admin/{id}/records/{section}',function($p){
        $site=record_id($p['id']);$id=($_POST['record_id'] ?? '')!==''?record_id($_POST['record_id']):null;$version=$id!==null?int_range($_POST['version'] ?? '',1,PHP_INT_MAX):null;
        WebAdminService::save($site,$p['section'],$_POST,$id,$version);return redirect('/web-admin/'.$site.'?section='.rawurlencode($p['section']),'Saved in this website’s local Admin Studio.');
    });
    $r->post('/web-admin/{id}/records/{record}/trash',function($p){$site=record_id($p['id']);$record=WebAdminService::record($site,record_id($p['record']));WebAdminService::trash($site,(int)$record['id'],int_range($_POST['version'] ?? '',1,PHP_INT_MAX));return redirect('/web-admin/'.$site.'?section='.$record['section'],'Moved to Trash. You can restore this record.');});
    $r->post('/web-admin/{id}/records/{record}/restore',function($p){$site=record_id($p['id']);WebAdminService::trash($site,record_id($p['record']),int_range($_POST['version'] ?? '',1,PHP_INT_MAX),true);return redirect('/web-admin/'.$site.'?section=trash','Record restored.');});
    $r->post('/web-admin/{id}/media',function($p){$site=record_id($p['id']);$file=$_FILES['file'] ?? null;if(!is_array($file))throw new InvalidArgumentException('Choose a media file.');WebAdminService::upload($site,$file);return redirect('/web-admin/'.$site.'?section=media','File saved in this website’s local media library.');});
    $r->get('/web-admin/{id}/media/{media}',function($p){
        $file=WebAdminService::mediaFile(record_id($p['id']),record_id($p['media']));$path=WebAdminService::mediaPath($file);if(!is_file($path))throw new InvalidArgumentException('This media file is missing from local storage.');
        header('Content-Type: '.$file['mime']);header('Content-Length: '.filesize($path));header('Content-Disposition: '.(str_starts_with($file['mime'],'image/')?'inline':'attachment')."; filename*=UTF-8''".rawurlencode($file['filename']));header('X-Content-Type-Options: nosniff');readfile($path);return '';
    });
    $r->get('/web-admin/{id}/export',function($p){
        $site=record_id($p['id']);$export=WebAdminService::export($site);$temporary=tempnam(sys_get_temp_dir(),'studio-export-');
        try{$zip=new ZipArchive();if($zip->open($temporary,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Cannot prepare workspace export.');$zip->addFromString('workspace.json',json_encode($export,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));foreach(Db::all('SELECT * FROM website_studio_media WHERE website_id=?',[$site]) as $file){$path=WebAdminService::mediaPath($file);if(!is_file($path))throw new InvalidArgumentException('A media file is missing. Restore it from a backup before exporting this workspace.');$zip->addFile($path,'media/'.$file['id'].'-'.basename($file['filename']));}$zip->close();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="LifeOS-Admin-Studio-'.$site.'.zip"');header('Content-Length: '.filesize($temporary));readfile($temporary);return '';}finally{if(is_file($temporary))unlink($temporary);}
    });
    $r->post('/web-admin/{id}/remote/refresh',function($p){$site=record_id($p['id']);$collection=text_input('collection',64);WebsiteService::refresh($site,$collection,int_range($_POST['offset'] ?? 0,0,1000000));return redirect('/web-admin/'.$site.'?section=connected&collection='.rawurlencode($collection),'Loaded records from the connected website.');});
    $r->post('/web-admin/{id}/remote/save',function($p){$site=record_id($p['id']);$collection=text_input('collection',64);$fields=$_POST['fields'] ?? [];if(!is_array($fields))throw new InvalidArgumentException('Enter valid website fields.');$record=text_input('record_id',128,false);WebsiteService::saveRecord($site,$collection,$fields,$record!==''?$record:null,$record!==''?int_range($_POST['version'] ?? '',1,PHP_INT_MAX):null);$message='Saved to the connected website.';try{WebsiteService::refresh($site,$collection,0);$message.=' Latest records loaded.';}catch(Throwable){$message.=' Refresh failed; use Load / refresh to retrieve the confirmed change before editing again.';}return redirect('/web-admin/'.$site.'?section=connected&collection='.rawurlencode($collection),$message);});
    $r->post('/web-admin/{id}/remote/delete',function($p){$site=record_id($p['id']);$collection=text_input('collection',64);WebsiteService::deleteRecord($site,$collection,text_input('record_id',128),int_range($_POST['version'] ?? '',1,PHP_INT_MAX));$message='Deleted from the connected website.';try{WebsiteService::refresh($site,$collection,0);$message.=' Latest records loaded.';}catch(Throwable){$message.=' Refresh failed; use Load / refresh to retrieve the updated collection.';}return redirect('/web-admin/'.$site.'?section=connected&collection='.rawurlencode($collection),$message);});
};
