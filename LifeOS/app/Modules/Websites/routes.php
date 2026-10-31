<?php
return function (Router $r) {
    $r->get('/websites',fn()=>View::render(__DIR__.'/views/index.php',['title'=>'Website Manager','profiles'=>WebsiteService::profiles(),'activity'=>WebsiteService::activity(),'costSnapshot'=>WebsiteCostService::summaries()]));
    $r->get('/websites/setup',function(){
        $catalog=ROOT.'/integrations/admin-references/capabilities.json';
        $references=is_file($catalog) ? (json_decode(file_get_contents($catalog),true,32,JSON_THROW_ON_ERROR)['references'] ?? []) : [];
        return View::render(__DIR__.'/views/setup.php',['title'=>'Connect a website','references'=>$references]);
    });
    $download=function(string $directory,string $filename): string {
        if (!is_dir($directory)) throw new InvalidArgumentException('This integration package is not included in this build.');
        $temporary=tempnam(sys_get_temp_dir(),'lifeos-connector-');
        try {
            $zip=new ZipArchive(); if ($zip->open($temporary,ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Could not prepare the integration package.');
            $root=realpath($directory);
            foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->isLink()) continue;
                $path=$file->getRealPath(); if (!str_starts_with($path,$root.DIRECTORY_SEPARATOR)) continue;
                $relative=str_replace('\\','/',substr($path,strlen($root)+1));
                if (str_starts_with(basename($relative),'.') || !in_array(strtolower(pathinfo($relative,PATHINFO_EXTENSION)),['php','md','json','ts','tsx','js','mjs','cjs','css'],true)) continue;
                $zip->addFile($path,$relative);
            }
            $zip->close(); header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.$filename.'"'); header('Content-Length: '.filesize($temporary)); readfile($temporary); return '';
        } finally { if(is_file($temporary)) unlink($temporary); }
    };
    $r->get('/websites/connector/download',fn()=>$download(ROOT.'/integrations/website-connector','LifeOS-Website-Connector.zip'));
    $r->get('/websites/gateway/download',fn()=>$download(ROOT.'/integrations/website-gateway','LifeOS-Website-Gateway.zip'));
    $r->post('/websites/gateway/login',function(){
        $count=WebsiteService::loginGateway(text_input('gateway_url',2048),text_input('username',120),text_input('password',4096));
        return redirect('/websites',$count.' assigned websites connected through your gateway.');
    });
    $r->get('/websites/adapters/{name}/download',function($p)use($download){
        if(!in_array($p['name'],['wordpress','nextjs'],true)) throw new InvalidArgumentException('Choose a supported website adapter.');
        return $download(ROOT.'/integrations/website-adapters/'.$p['name'],'LifeOS-'.$p['name'].'-adapter.zip');
    });
    $r->get('/websites/references/{name}/download',function($p)use($download){
        if(!in_array($p['name'],['creativefx','tenderhub'],true)) throw new InvalidArgumentException('Choose a reference panel.');
        return $download(ROOT.'/integrations/admin-references/'.$p['name'],'LifeOS-'.$p['name'].'-reference.zip');
    });
    $r->post('/websites/profile',function(){ $id=WebsiteService::save(null,$_POST); return redirect('/websites/'.$id,'Website saved locally. Connect its API to generate the management panel.'); });
    $r->get('/websites/{id}',function($p){
        $id=record_id($p['id']); $profile=WebsiteService::profile($id); $connection=WebsiteService::session($id);
        $collections=$connection['manifest']['collections'] ?? [];
        $selected=$_GET['collection'] ?? ($collections[0]['id'] ?? '');
        if(!is_string($selected)) throw new InvalidArgumentException('Choose a collection.');
        $collection=$selected!=='' && $connection ? WebsiteService::collection($id,$selected) : null;
        $cached=$collection ? WebsiteService::cached($id,$selected) : null;
        $edit=null; $editId=$_GET['edit'] ?? '';
        if(!is_string($editId)) throw new InvalidArgumentException('Choose a record to edit.');
        if($editId!=='') { foreach($cached['records'] ?? [] as $row) if((string)$row['id']===$editId) $edit=$row; if(!$edit) throw new InvalidArgumentException('Load the record page again before editing.'); }
        $costMonth=$_GET['cost_month'] ?? date('Y-m');
        if(!is_string($costMonth)) throw new InvalidArgumentException('Choose a cost month.');
        $costMonth=in_array($costMonth,['','all'],true) ? null : valid_month($costMonth);
        return View::render(__DIR__.'/views/show.php',['title'=>$profile['name'],'profile'=>$profile,'connection'=>$connection,'collections'=>$collections,'collection'=>$collection,'cached'=>$cached,'edit'=>$edit,'operations'=>WebsiteService::operations($id),'costMonth'=>$costMonth,'costSnapshot'=>WebsiteCostService::summaries($costMonth),'costRecords'=>WebsiteCostService::records($id,$costMonth),'costAccounts'=>Db::all('SELECT * FROM accounts ORDER BY name'),'costCategories'=>Db::all('SELECT * FROM expense_categories ORDER BY name')]);
    });
    $r->post('/websites/{id}/costs',function($p){$id=record_id($p['id']);WebsiteCostService::add($id,$_POST);return redirect('/websites/'.$id.'?cost_month='.substr($_POST['date'],0,7).'#maintenance-costs','Website cost saved.');});
    $r->post('/websites/{id}/costs/{cost}/delete',function($p){$id=record_id($p['id']);WebsiteCostService::remove($id,record_id($p['cost']));return redirect('/websites/'.$id.'#maintenance-costs','Website cost removed. Any linked Money expense was reversed.');});
    $r->post('/websites/{id}/profile',function($p){$id=record_id($p['id']); WebsiteService::save($id,$_POST);return redirect('/websites/'.$id,'Profile updated. Reconnect to use the new configuration.');});
    $r->post('/websites/{id}/remove',function($p){WebsiteService::remove(record_id($p['id']));return redirect('/websites','Local profile removed. The remote website was not changed.');});
    $r->post('/websites/{id}/login',function($p){
        $id=record_id($p['id']); $password=$_POST['password'] ?? '';
        if(!is_string($password)) throw new InvalidArgumentException('Enter a valid website password.');
        WebsiteService::login($id,text_input('username',120),$password);
        return redirect('/websites/'.$id,'Signed in. Your website admin panel is ready.');
    });
    $r->post('/websites/{id}/connect',function($p){$id=record_id($p['id']);WebsiteService::connect($id,text_input('token',4096));return redirect('/websites/'.$id,'Connected. The panel is generated from the capabilities supplied by your website.');});
    $r->post('/websites/{id}/disconnect',function($p){$id=record_id($p['id']);WebsiteService::logout($id);return redirect('/websites/'.$id,'Disconnected and session credentials cleared.');});
    $r->post('/websites/{id}/refresh',function($p){$id=record_id($p['id']);$collection=text_input('collection',80);WebsiteService::refresh($id,$collection,int_range($_POST['offset'] ?? 0,0,1000000));return redirect('/websites/'.$id.'?collection='.rawurlencode($collection),'Records loaded from your connected website.');});
    $r->post('/websites/{id}/record',function($p){
        $id=record_id($p['id']);$collection=text_input('collection',80);$fields=$_POST['fields'] ?? [];
        if(!is_array($fields)) throw new InvalidArgumentException('Enter valid record fields.');
        $record=text_input('record_id',200,false);$version=$record!=='' ? int_range($_POST['version'] ?? '',1,PHP_INT_MAX) : null;
        WebsiteService::saveRecord($id,$collection,$fields,$record!=='' ? $record : null,$version);
        return redirect('/websites/'.$id.'?collection='.rawurlencode($collection),'Saved to the connected website. Load / refresh to see the latest records.');
    });
    $r->post('/websites/{id}/record/delete',function($p){$id=record_id($p['id']);$collection=text_input('collection',80);WebsiteService::deleteRecord($id,$collection,text_input('record_id',200),int_range($_POST['version'] ?? '',1,PHP_INT_MAX));return redirect('/websites/'.$id.'?collection='.rawurlencode($collection),'Remote record deleted. Load / refresh for the latest records.');});
};
