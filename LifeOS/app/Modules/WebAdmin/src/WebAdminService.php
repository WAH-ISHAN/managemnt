<?php
declare(strict_types=1);

final class WebAdminService {
    public static function sections():array {
        $text=static fn(string $label,string $type='text',bool $required=false,int $max=2000)=>compact('label','type','required','max');
        $copy=['draft'=>'Draft','ready'=>'Ready','archived'=>'Archived'];
        $section=static fn(string $label,string $group,array $fields,array $statuses=[]):array=>compact('label','group','fields')+['statuses'=>$statuses ?: $copy];
        return [
            'homepage'=>$section('Homepage','Content',['eyebrow'=>$text('Eyebrow'),'heading'=>$text('Main heading'),'body'=>$text('Introduction','textarea'),'image'=>$text('Image URL','url'),'button_text'=>$text('Button text'),'button_url'=>$text('Button URL','url')]),
            'projects'=>$section('Works & projects','Content',['category'=>$text('Category'),'client'=>$text('Client'),'summary'=>$text('Summary','textarea'),'body'=>$text('Project story','textarea',false,20000),'image'=>$text('Cover image URL','url'),'link'=>$text('Project URL','url'),'date'=>$text('Project date','date')]),
            'weddings'=>$section('Weddings & stories','Content',['date'=>$text('Date','date'),'location'=>$text('Location'),'body'=>$text('Story','textarea',false,20000),'image'=>$text('Cover image URL','url'),'video'=>$text('Video URL','url')]),
            'services'=>$section('Services','Content',['summary'=>$text('Introduction','textarea'),'features'=>$text('Features, one per line','textarea'),'price'=>$text('Starting price (Rs.)','money'),'image'=>$text('Image URL','url')]),
            'about'=>$section('About & team','Content',['role'=>$text('Role / section'),'body'=>$text('Biography / story','textarea',false,20000),'image'=>$text('Portrait / image URL','url'),'link'=>$text('Profile URL','url')]),
            'creative_pool'=>$section('Creative pool','Content',['category'=>$text('Specialty'),'email'=>$text('Email','email'),'phone'=>$text('Phone'),'portfolio'=>$text('Portfolio URL','url'),'body'=>$text('Profile','textarea')]),
            'pages'=>$section('Pages','Content',['slug'=>$text('Page slug','slug',true,120),'body'=>$text('Page content','textarea',false,20000),'image'=>$text('Featured image URL','url'),'meta_title'=>$text('SEO title'),'meta_description'=>$text('SEO description','textarea')]),
            'blog'=>$section('Blog & articles','Content',['slug'=>$text('Article slug','slug',true,120),'author'=>$text('Author'),'body'=>$text('Article content','textarea',false,20000),'image'=>$text('Cover image URL','url'),'date'=>$text('Article date','date')]),
            'navigation'=>$section('Navigation & footer','Website',['label'=>$text('Link label'),'url'=>$text('Link URL','url'),'group'=>$text('Menu / footer group'),'position'=>$text('Display order','integer'),'notes'=>$text('Notes','textarea')]),
            'seo'=>$section('SEO & search','Website',['page_url'=>$text('Page URL','url'),'meta_title'=>$text('SEO title'),'description'=>$text('Description','textarea'),'canonical'=>$text('Canonical URL','url'),'og_image'=>$text('Social image URL','url'),'keywords'=>$text('Keywords'),'notes'=>$text('Search verification / notes','textarea')]),
            'branding'=>$section('Theme & branding','Website',['accent'=>$text('Accent colour','color'),'background'=>$text('Background colour','color'),'font'=>$text('Font family'),'logo'=>$text('Logo URL','url'),'notes'=>$text('Brand guidelines','textarea')]),
            'customers'=>$section('Customers & organisations','Operations',['email'=>$text('Email','email'),'phone'=>$text('Phone'),'company'=>$text('Organisation'),'address'=>$text('Address','textarea'),'notes'=>$text('Notes','textarea')],['active'=>'Active','lead'=>'Lead','inactive'=>'Inactive']),
            'inquiries'=>$section('Inquiries & support','Operations',['name'=>$text('Contact name'),'email'=>$text('Email','email'),'phone'=>$text('Phone'),'service'=>$text('Service / subject'),'message'=>$text('Message','textarea',true,10000),'reply'=>$text('Reply notes','textarea',false,10000)],['new'=>'New','in_progress'=>'In progress','resolved'=>'Resolved','archived'=>'Archived']),
            'tasks'=>$section('Tasks & workflow','Operations',['owner'=>$text('Assigned to'),'due_date'=>$text('Due date','date'),'priority'=>$text('Priority'),'notes'=>$text('Details','textarea')],['todo'=>'To do','in_progress'=>'In progress','done'=>'Done','cancelled'=>'Cancelled']),
            'invoices'=>$section('Invoices & payments','Operations',['reference'=>$text('Invoice number','text',true,120),'customer'=>$text('Customer','text',true),'amount'=>$text('Amount (Rs.)','money',true),'due_date'=>$text('Due date','date'),'notes'=>$text('Invoice / payment notes','textarea')],['draft'=>'Draft','sent'=>'Sent','paid'=>'Paid','overdue'=>'Overdue','cancelled'=>'Cancelled']),
            'campaigns'=>$section('Campaigns','Operations',['channel'=>$text('Channel'),'start_date'=>$text('Start date','date'),'end_date'=>$text('End date','date'),'budget'=>$text('Budget (Rs.)','money'),'link'=>$text('Campaign URL','url'),'notes'=>$text('Plan / results','textarea')],['draft'=>'Draft','active'=>'Active','completed'=>'Completed','cancelled'=>'Cancelled']),
            'integrations'=>$section('Integrations & references','Workspace',['service'=>$text('Service'),'url'=>$text('Public dashboard URL','url'),'owner'=>$text('Managed by'),'notes'=>$text('Configuration notes (no passwords)','textarea')],['planned'=>'Planned','active'=>'Active','paused'=>'Paused']),
        ];
    }
    public static function definition(string $section):array {return self::sections()[$section] ?? throw new InvalidArgumentException('Choose an Admin Studio section.');}
    private static function text(mixed $value,int $max,bool $required=false):string {
        if(!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0") || mb_strlen($value)>$max || ($required && trim($value)===''))throw new InvalidArgumentException('Complete valid fields within their allowed length.');
        return trim($value);
    }
    public static function save(int $site,string $section,array $input,?int $id=null,?int $version=null):int {
        WebsiteService::profile($site);$schema=self::definition($section);
        $title=self::text($input['title'] ?? '',200,true);$status=self::text($input['status'] ?? '',30,true);
        if(!isset($schema['statuses'][$status]))throw new InvalidArgumentException('Choose a valid record status.');
        $fields=$input['fields'] ?? [];
        if(!is_array($fields) || array_diff(array_keys($fields),array_keys($schema['fields'])))throw new InvalidArgumentException('This form contains unknown fields.');
        $data=[];
        foreach($schema['fields'] as $name=>$field){
            $value=self::text($fields[$name] ?? '',$field['max'],$field['required']);
            if($value!==''){
                if($field['type']==='date')$value=valid_date($value);
                if($field['type']==='money')$value=number_format(cents($value,true)/100,2,'.','');
                if($field['type']==='integer' && (!ctype_digit($value) || strlen($value)>6))throw new InvalidArgumentException('Enter a display order from 0 to 999999.');
                if($field['type']==='email' && !filter_var($value,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');
                if($field['type']==='url'){
                    $parts=parse_url($value);
                    if(!$parts || !in_array(strtolower($parts['scheme'] ?? ''),['https','http'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/',$value))throw new InvalidArgumentException('Use a complete HTTP or HTTPS URL without credentials.');
                }
                if($field['type']==='slug' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$value))throw new InvalidArgumentException('Use lowercase letters, numbers and hyphens for the slug.');
                if($field['type']==='color' && !preg_match('/^#[a-fA-F0-9]{6}$/D',$value))throw new InvalidArgumentException('Enter a six-digit hex colour, such as #2563eb.');
            }
            $data[$name]=$value;
        }
        return Db::tx(function()use($site,$section,$title,$status,$data,$id,$version):int{
            self::unique($site,$section,$data,$id);
            if($id!==null){
                $record=self::record($site,$id);if($record['section']!==$section || $record['deleted_at']!==null)throw new InvalidArgumentException('Choose an active record from this section.');
                if($version!==$record['version'])throw new InvalidArgumentException('This record changed. Reload it before saving.');
                Db::run('UPDATE website_studio_records SET title=?,status=?,data=?,version=version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$title,$status,json_encode($data,JSON_THROW_ON_ERROR),$id]);
            }else $id=Db::run('INSERT INTO website_studio_records(website_id,section,title,status,data) VALUES (?,?,?,?,?)',[$site,$section,$title,$status,json_encode($data,JSON_THROW_ON_ERROR)]);
            self::log($site,$id!==null && $version!==null?'updated':'created',$section,$title);return $id;
        });
    }
    public static function record(int $site,int $id):array {
        WebsiteService::profile($site);$row=Db::one('SELECT * FROM website_studio_records WHERE website_id=? AND id=?',[$site,$id]);
        if(!$row)throw new InvalidArgumentException('Admin Studio record not found for this website.');
        $row['version']=(int)$row['version'];$row['values']=json_decode($row['data'],true,32,JSON_THROW_ON_ERROR);return $row;
    }
    private static function unique(int $site,string $section,array $data,?int $id):void {
        $key=match($section){'pages','blog'=>'slug','invoices'=>'reference',default=>null};
        if($key!==null && Db::one('SELECT id FROM website_studio_records WHERE website_id=? AND section=? AND deleted_at IS NULL AND lower(json_extract(data,?))=lower(?) AND id<>? LIMIT 1',[$site,$section,'$.'.$key,$data[$key],$id ?? 0]))throw new InvalidArgumentException('This website already has a record with that '.($key==='slug'?'slug':'invoice number').'.');
    }
    public static function listing(int $site,string $section,string $query='',string $status='',int $page=1):array {
        WebsiteService::profile($site);$schema=self::definition($section);$query=self::text($query,150);
        if($status!=='' && !isset($schema['statuses'][$status]))throw new InvalidArgumentException('Choose a valid status filter.');
        $where='website_id=? AND section=? AND deleted_at IS NULL';$args=[$site,$section];
        if($query!==''){$where.=" AND (instr(lower(title),lower(?))>0 OR instr(lower(data),lower(?))>0)";$args[]=$query;$args[]=$query;}
        if($status!==''){$where.=' AND status=?';$args[]=$status;}
        $count=(int)Db::one('SELECT COUNT(*) n FROM website_studio_records WHERE '.$where,$args)['n'];$pages=max(1,(int)ceil($count/20));$page=max(1,min($page,$pages));
        $rows=Db::all('SELECT * FROM website_studio_records WHERE '.$where.' ORDER BY updated_at DESC,id DESC LIMIT 20 OFFSET '.(($page-1)*20),$args);
        foreach($rows as &$row){$row['values']=json_decode($row['data'],true,32,JSON_THROW_ON_ERROR);$row['version']=(int)$row['version'];}unset($row);
        return compact('rows','count','page','pages','query','status');
    }
    public static function trash(int $site,int $id,int $version,bool $restore=false):void {
        Db::tx(function()use($site,$id,$version,$restore){$row=self::record($site,$id);if($row['version']!==$version)throw new InvalidArgumentException('This record changed. Reload before continuing.');if(($row['deleted_at']===null)===$restore)throw new InvalidArgumentException('This record is already in that state.');if($restore)self::unique($site,$row['section'],$row['values'],$id);Db::run('UPDATE website_studio_records SET deleted_at='.($restore?'NULL':'CURRENT_TIMESTAMP').',updated_at=CURRENT_TIMESTAMP,version=version+1 WHERE id=?',[$id]);self::log($site,$restore?'restored':'moved to trash',$row['section'],$row['title']);});
    }
    public static function summary(int $site):array {
        WebsiteService::profile($site);$counts=[];foreach(Db::all('SELECT section,COUNT(*) n FROM website_studio_records WHERE website_id=? AND deleted_at IS NULL GROUP BY section',[$site]) as $row)$counts[$row['section']]=(int)$row['n'];
        $content=0;$operations=0;foreach(self::sections() as $key=>$section)if(in_array($section['group'],['Content','Website'],true))$content+=$counts[$key] ?? 0;else $operations+=$counts[$key] ?? 0;
        $open=(int)Db::one("SELECT COUNT(*) n FROM website_studio_records WHERE website_id=? AND deleted_at IS NULL AND ((section='tasks' AND status IN ('todo','in_progress')) OR (section='inquiries' AND status IN ('new','in_progress')))",[$site])['n'];
        $invoice=Db::one("SELECT COALESCE(SUM(CASE WHEN status='paid' THEN CAST(ROUND(CAST(json_extract(data,'$.amount') AS REAL)*100) AS INTEGER) ELSE 0 END),0) paid,COALESCE(SUM(CASE WHEN status IN ('sent','overdue') THEN CAST(ROUND(CAST(json_extract(data,'$.amount') AS REAL)*100) AS INTEGER) ELSE 0 END),0) due FROM website_studio_records WHERE website_id=? AND section='invoices' AND deleted_at IS NULL",[$site]);
        $media=Db::one('SELECT COUNT(*) n,COALESCE(SUM(bytes),0) bytes FROM website_studio_media WHERE website_id=?',[$site]);
        return ['counts'=>$counts,'content'=>$content,'operations'=>$operations,'open'=>$open,'paid'=>(int)$invoice['paid']/100,'due'=>(int)$invoice['due']/100,'media'=>(int)$media['n'],'bytes'=>(int)$media['bytes'],'activity'=>self::activity($site),'recent'=>Db::all('SELECT * FROM website_studio_records WHERE website_id=? AND deleted_at IS NULL ORDER BY updated_at DESC,id DESC LIMIT 6',[$site]),'statuses'=>Db::all('SELECT status,COUNT(*) n FROM website_studio_records WHERE website_id=? AND deleted_at IS NULL GROUP BY status ORDER BY n DESC',[$site])];
    }
    private static function log(int $site,string $action,string $section,string $title):void {Db::run('INSERT INTO website_studio_activity(website_id,action,section,record_title) VALUES (?,?,?,?)',[$site,$action,$section,$title]);}
    public static function activity(int $site):array {WebsiteService::profile($site);return Db::all('SELECT * FROM website_studio_activity WHERE website_id=? ORDER BY id DESC LIMIT 50',[$site]);}
    public static function deleted(int $site):array {WebsiteService::profile($site);return Db::all('SELECT * FROM website_studio_records WHERE website_id=? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC,id DESC LIMIT 100',[$site]);}
    public static function media(int $site):array {WebsiteService::profile($site);return Db::all('SELECT * FROM website_studio_media WHERE website_id=? ORDER BY id DESC LIMIT 100',[$site]);}
    public static function mediaFile(int $site,int $id):array {WebsiteService::profile($site);return Db::one('SELECT * FROM website_studio_media WHERE website_id=? AND id=?',[$site,$id]) ?? throw new InvalidArgumentException('Media file not found for this website.');}
    public static function mediaPath(array $file):string {
        if(!defined('DATA') || !preg_match('/^[a-f0-9]{48}\.bin$/D',$file['storage_name']))throw new RuntimeException('Media storage unavailable.');return DATA.'/uploads/website-studio/'.$file['storage_name'];
    }
    public static function upload(int $site,array $upload):int {
        WebsiteService::profile($site);
        if(($upload['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name']))throw new InvalidArgumentException('Choose a file to upload.');
        $info=self::inspectMedia($upload['tmp_name']);$bytes=$info['bytes'];$mime=$info['mime'];$sha=$info['sha256'];
        $filename=self::text($upload['name'] ?? 'upload',200,true);$filename=basename(str_replace('\\','/',$filename));
        $storage=bin2hex(random_bytes(24)).'.bin';$path=self::mediaPath(['storage_name'=>$storage]);if(!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true))throw new RuntimeException('Cannot prepare media storage.');
        if(!move_uploaded_file($upload['tmp_name'],$path))throw new RuntimeException('Cannot save uploaded media.');@chmod($path,0600);
        try{return Db::tx(function()use($site,$filename,$storage,$mime,$bytes,$sha){$id=Db::run('INSERT INTO website_studio_media(website_id,filename,storage_name,mime,bytes,sha256) VALUES (?,?,?,?,?,?)',[$site,$filename,$storage,$mime,$bytes,$sha]);self::log($site,'uploaded','media',$filename);return $id;});}catch(Throwable $e){unlink($path);throw $e;}
    }
    public static function inspectMedia(string $path):array {
        if(!is_file($path) || is_link($path))throw new InvalidArgumentException('Choose a regular media file.');
        $bytes=filesize($path);if($bytes===false || $bytes<1 || $bytes>5*1024*1024)throw new InvalidArgumentException('Choose a file up to 5 MB.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);if(!in_array($mime,['image/jpeg','image/png','image/webp','image/gif','application/pdf','video/mp4'],true))throw new InvalidArgumentException('Use JPG, PNG, WebP, GIF, PDF or MP4 files.');
        return ['bytes'=>$bytes,'mime'=>$mime,'sha256'=>hash_file('sha256',$path)];
    }
    public static function export(int $site):array {return ['format'=>'lifeos-admin-studio-v1','exported_at'=>gmdate('c'),'website'=>array_intersect_key(WebsiteService::profile($site),array_flip(['name','base_url','business_name'])),'records'=>Db::all('SELECT section,title,status,data,version,created_at,updated_at,deleted_at FROM website_studio_records WHERE website_id=? ORDER BY id',[$site]),'media'=>array_map(fn($file)=>array_intersect_key($file,array_flip(['id','filename','mime','bytes','created_at'])),Db::all('SELECT * FROM website_studio_media WHERE website_id=? ORDER BY id',[$site]))];}
}
