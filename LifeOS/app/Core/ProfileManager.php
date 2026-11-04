<?php
declare(strict_types=1);
/** Installation registry. Each workspace owns its database, uploads, backups and sessions. */
final class ProfileManager {
    private static ?PDO $registry=null;
    private static string $root='';
    private static string $current='default';
    public static function init(string $root): void {
        self::$root=rtrim($root,'/\\');
        if (!is_dir(self::$root)) mkdir(self::$root,0700,true);
        self::$registry=new PDO('sqlite:'.self::$root.'/profiles.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        self::$registry->exec('PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; CREATE TABLE IF NOT EXISTS profiles(id TEXT PRIMARY KEY,name TEXT NOT NULL,kid_mode INTEGER NOT NULL DEFAULT 0,attempts INTEGER NOT NULL DEFAULT 0,blocked_until INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP); CREATE TABLE IF NOT EXISTS guardian_links(guardian_id TEXT NOT NULL REFERENCES profiles(id),child_id TEXT NOT NULL REFERENCES profiles(id),created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(guardian_id,child_id),CHECK(guardian_id<>child_id)); CREATE TABLE IF NOT EXISTS profile_audit(id INTEGER PRIMARY KEY,actor TEXT NOT NULL,subject TEXT NOT NULL,action TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP); CREATE TABLE IF NOT EXISTS registry_settings(key TEXT PRIMARY KEY,value TEXT NOT NULL)');
        self::query("INSERT OR IGNORE INTO profiles(id,name) VALUES ('default','Your workspace')");
        self::$registry->exec("CREATE TABLE IF NOT EXISTS family_board(id INTEGER PRIMARY KEY,guardian_id TEXT NOT NULL,child_id TEXT NOT NULL,title TEXT NOT NULL,due TEXT NOT NULL,done INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(guardian_id,child_id) REFERENCES guardian_links(guardian_id,child_id) ON DELETE CASCADE)");
    }
    public static function close(): void { self::$registry=null; }
    private static function query(string $sql,array $args=[]): PDOStatement { $s=self::$registry->prepare($sql); $s->execute($args); return $s; }
    public static function profiles(): array { return self::query('SELECT id,name,kid_mode FROM profiles ORDER BY created_at,id')->fetchAll(); }
    public static function exists(string $id): bool { return ($id==='default'||preg_match('/^[a-f0-9]{32}$/D',$id)) && (bool)self::query('SELECT 1 FROM profiles WHERE id=?',[$id])->fetchColumn(); }
    public static function id(): string { return self::$current; }
    public static function select(string $id): void { if (!self::exists($id)) throw new InvalidArgumentException(t('Choose an existing profile.')); self::$current=$id; }
    public static function directory(?string $id=null): string { $id??=self::$current; if (!self::exists($id)) throw new InvalidArgumentException(t('Choose an existing profile.')); return $id==='default' ? self::$root : self::$root.'/profiles/'.$id; }
    public static function active(): string { $id=(string)(self::query("SELECT value FROM registry_settings WHERE key='active'")->fetchColumn() ?: 'default'); return self::exists($id) ? $id : 'default'; }
    public static function isKid(?string $id=null): bool { return self::$registry!==null && (bool)self::query('SELECT kid_mode FROM profiles WHERE id=?',[$id??self::$current])->fetchColumn(); }
    public static function syncName(string $name): void { if (self::$registry!==null && trim($name)!=='') { $name=mb_substr($name,0,100); self::query('UPDATE profiles SET name=? WHERE id=? AND name<>?',[$name,self::$current,$name]); } }
    public static function create(string $name,string $pin): string {
        if (Db::setting('pin_hash')==='') throw new InvalidArgumentException(t('Set a PIN on your current profile before creating another profile.'));
        $name=trim($name); if ($name===''||mb_strlen($name)>100||!preg_match('/^[0-9]{4,12}$/D',$pin)) throw new InvalidArgumentException(t('Enter a profile name and a PIN with 4 to 12 digits.'));
        $id=bin2hex(random_bytes(16)); $dir=self::$root.'/profiles/'.$id; mkdir($dir,0700,true);
        try {
            foreach (['sessions','uploads','backups'] as $folder) mkdir($dir.'/'.$folder,0700);
            $db=new PDO('sqlite:'.$dir.'/lifeos.db',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA foreign_keys=ON; CREATE TABLE migrations(file TEXT PRIMARY KEY,checksum TEXT NOT NULL,applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
            $dirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',Modules::discover(ROOT.'/app/Modules')));
            foreach ($dirs as $migrationDir) { $files=glob($migrationDir.'/*.sql')?:[]; sort($files); foreach($files as $file) {
                $content=str_replace("\r\n","\n",file_get_contents($file)); $db->beginTransaction();
                try { $db->exec($content); $stmt=$db->prepare('INSERT INTO migrations(file,checksum) VALUES (?,?)'); $stmt->execute([basename(dirname($migrationDir)).'/'.basename($file),hash('sha256',$content)]); $db->commit(); } catch(Throwable $e) { $db->rollBack(); throw $e; }
            } }
            $stmt=$db->prepare('INSERT INTO settings(key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
            foreach (['name'=>$name,'pin_hash'=>password_hash($pin,PASSWORD_DEFAULT)] as $key=>$value) $stmt->execute([$key,$value]);
            $db=null; self::query('INSERT INTO profiles(id,name) VALUES (?,?)',[$id,$name]); self::audit($id,'created'); return $id;
        } catch(Throwable $e) { foreach (glob($dir.'/*')?:[] as $file) { if (is_file($file)) unlink($file); elseif(is_dir($file)) rmdir($file); } rmdir($dir); throw $e; }
    }
    private static function database(string $id): PDO {
        $file=self::directory($id).'/lifeos.db'; if (!is_file($file)) throw new InvalidArgumentException(t('This profile has not been initialized.'));
        $db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); $db->exec('PRAGMA query_only=ON; PRAGMA busy_timeout=5000'); return $db;
    }
    public static function verify(string $id,string $pin,bool $requirePin=false): void {
        if(!self::exists($id)) throw new InvalidArgumentException(t('Choose an existing profile.'));
        $row=self::query('SELECT attempts,blocked_until FROM profiles WHERE id=?',[$id])->fetch();
        if((int)$row['blocked_until']>time()) throw new InvalidArgumentException(t('Too many attempts. Try again in a minute.'));
        $db=self::database($id); $stmt=$db->query("SELECT value FROM settings WHERE key='pin_hash'"); $hash=(string)($stmt->fetchColumn()?:'');
        if(($hash===''&&$requirePin)||($hash!==''&&!password_verify($pin,$hash))) {
            $tries=(int)$row['attempts']+1; self::query('UPDATE profiles SET attempts=?,blocked_until=? WHERE id=?',[$tries>=5?0:$tries,$tries>=5?time()+60:0,$id]);
            throw new InvalidArgumentException(t('That profile PIN did not match. Guardian links require a PIN on both profiles.'));
        }
        self::query('UPDATE profiles SET attempts=0,blocked_until=0 WHERE id=?',[$id]);
    }
    public static function switchTo(string $id,string $pin): void {
        self::verify($id,$pin); self::audit($id,'switched');
        // Invalidate the source session and issue a new target session; never reuse authentication.
        $_SESSION=[]; session_regenerate_id(true); session_write_close();
        self::select($id); $directory=self::directory(); if(!is_dir($directory.'/sessions')) mkdir($directory.'/sessions',0700,true);
        session_save_path($directory.'/sessions'); session_name(self::sessionName()); session_id(''); session_start(); session_regenerate_id(true);
        $db=self::database($id); $hash=(string)($db->query("SELECT value FROM settings WHERE key='pin_hash'")->fetchColumn()?:'');
        $_SESSION=['csrf'=>bin2hex(random_bytes(32)),'auth_hash'=>$hash,'auth_instance'=>getenv('LIFEOS_INSTANCE')?:'development-'.getmypid()];
        setcookie('lifeos_profile',$id,['httponly'=>true,'samesite'=>'Strict','path'=>'/']);
        self::query("INSERT INTO registry_settings(key,value) VALUES ('active',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",[$id]);
    }
    public static function sessionName(): string { return self::$current==='default' ? 'lifeos' : 'lifeos_'.self::$current; }
    private static function audit(string $subject,string $action): void { self::query('INSERT INTO profile_audit(actor,subject,action) VALUES (?,?,?)',[self::$current,$subject,$action]); }
    public static function link(string $child,string $guardianPin,string $childPin): void {
        if(self::isKid()||$child===self::$current) throw new InvalidArgumentException(t('Choose a separate child profile from an adult workspace.'));
        self::verify(self::$current,$guardianPin,true); self::verify($child,$childPin,true);
        self::query('INSERT OR IGNORE INTO guardian_links(guardian_id,child_id) VALUES (?,?)',[self::$current,$child]); self::audit($child,'guardian-consent-granted');
    }
    public static function children(): array { return self::query('SELECT p.id,p.name,p.kid_mode FROM guardian_links g JOIN profiles p ON p.id=g.child_id WHERE g.guardian_id=?',[self::$current])->fetchAll(); }
    private static function canView(string $child): bool { return !self::isKid()&&(bool)self::query('SELECT 1 FROM guardian_links WHERE guardian_id=? AND child_id=?',[self::$current,$child])->fetchColumn(); }
    public static function policy(string $child,bool $kid,string $guardianPin): void {
        if(!self::canView($child)) throw new InvalidArgumentException(t('No guardian permission for this profile.'));
        self::verify(self::$current,$guardianPin,true); self::query('UPDATE profiles SET kid_mode=? WHERE id=?',[(int)$kid,$child]); self::audit($child,$kid?'kid-mode-enabled':'kid-mode-disabled');
    }
    public static function revoke(string $guardian): void { self::query('DELETE FROM guardian_links WHERE guardian_id=? AND child_id=?',[$guardian,self::$current]); self::audit(self::$current,'guardian-consent-revoked'); }
    public static function guardians(): array { return self::query('SELECT p.id,p.name FROM guardian_links g JOIN profiles p ON p.id=g.guardian_id WHERE g.child_id=?',[self::$current])->fetchAll(); }
    public static function report(string $child): array {
        if(!self::canView($child)) throw new InvalidArgumentException(t('No guardian permission for this profile.'));
        $db=self::database($child); $read=fn($sql)=>$db->query($sql)->fetchAll();
        self::audit($child,'guardian-report-viewed');
        return ['name'=>(string)self::query('SELECT name FROM profiles WHERE id=?',[$child])->fetchColumn(),'tasks'=>$read('SELECT title,due,status FROM tasks ORDER BY id DESC LIMIT 100'),'habits'=>$read('SELECT h.title,(SELECT COUNT(*) FROM planner_habit_checks c WHERE c.habit_id=h.id) checkins FROM planner_habits h WHERE active=1'),'focus'=>$read("SELECT substr(completed_at,1,10) day,SUM(elapsed_seconds)/60 minutes FROM focus_sessions WHERE status='complete' AND completed_at IS NOT NULL GROUP BY day ORDER BY day DESC LIMIT 30")];
    }
    public static function kidAllowed(string $method,string $path): bool {
        if($method==='GET'&&$path==='/family')return true;
        if($method==='POST'&&preg_match('#^/family/[0-9]+/done$#D',$path))return true;
        if($method==='POST'&&in_array($path,['/school/xp/settings','/school/reward/redeem','/school/calendar'],true))return true;
        if(in_array($path,['/idle-lock','/session/activity','/unlock/hello'],true))return true;
        if($method==='POST'&&preg_match('#^/school/bag/[0-9]+/pack$#D',$path))return true;
        if($method==='GET'&&in_array($path,['/school','/school/report'],true))return true;
        if($method==='POST'&&preg_match('#^/school/(?:[a-z]+/save|[a-z]+/[0-9]+/(?:complete|archive)|rewards/[0-9]+/request|requests/[0-9]+/decision)$#D',$path))return true;
        if(in_array($path,['/unlock','/lock','/profiles','/profiles/switch','/profiles/revoke','/kid','/settings/workspace/language'],true)) return true;
        if($method==='GET') return $path==='/planner'||$path==='/focus'||str_starts_with($path,'/insights/alerts');
        return (bool)preg_match('#^/(?:planner/tasks(?:/[0-9]+/(?:edit|status))?|planner/habits/[0-9]+/check|planner/routines/[0-9]+/check|focus/start|focus/[0-9]+/action)$#D',$path);
    }
    public static function guard(Router $router): void {
        $router->before(function(string $method,string $path) {
            if(self::isKid()&&($path==='/'||($method==='GET'&&in_array($path,['/planner','/planner/capture'],true)))) { echo redirect('/kid'); exit; }
            if(self::isKid()&&!self::kidAllowed($method,$path)) { http_response_code(403); echo View::render(ROOT.'/app/Core/views/error.php',['title'=>t('Guardian setting'),'message'=>t('Ask your guardian to change this setting from their profile.'),'back'=>'/kid']); exit; }
        });
    }
    public static function register(Router $router): void {
        $router->get('/family',fn()=>View::render(ROOT.'/app/Modules/School/views/family.php',['title'=>t('Family board'),'items'=>self::familyItems()],'lock'));
        $router->post('/family',function(){self::familyAdd(text_input('child',40),text_input('title',200),valid_date(text_input('due')),text_input('pin',12));return redirect('/family','Family chore assigned.');});
        $router->post('/family/{id}/done',function($p){self::familyDone(record_id($p['id']));return redirect('/family','Shared chore completed.');});
        $router->get('/profiles/child/{id}/school',function($p){return View::render(ROOT.'/app/Modules/School/views/guardian.php',['title'=>t('Weekly school report'),'report'=>self::schoolReport($p['id'],valid_date((string)($_GET['date']??date('Y-m-d'))))],'lock');});
        $router->get('/profiles',fn()=>View::render(ROOT.'/app/Core/views/profiles.php',['title'=>t('Profiles & family')],'lock'));
        $router->post('/profiles/switch',function() { self::switchTo(text_input('profile',40),text_input('pin',12,false)); return redirect('/'); });
        $router->post('/profiles/create',function() { $id=self::create(text_input('name',100),text_input('pin',12)); return redirect('/profiles',t('Profile created. Select it and enter its PIN to begin.')); });
        $router->post('/profiles/link',function() { self::link(text_input('child',40),text_input('guardian_pin',12),text_input('child_pin',12)); return redirect('/profiles',t('Guardian access approved by both profiles.')); });
        $router->post('/profiles/policy',function() { self::policy(text_input('child',40),isset($_POST['kid_mode']),text_input('guardian_pin',12)); return redirect('/profiles',t('Child policy saved.')); });
        $router->post('/profiles/revoke',function() { self::revoke(text_input('guardian',40)); return redirect('/profiles',t('Guardian access removed.')); });
        $router->get('/profiles/child/{id}/report',fn($p)=>View::render(ROOT.'/app/Core/views/guardian-report.php',['title'=>t('Child activity report'),'report'=>self::report($p['id'])],'lock'));
        $router->get('/kid',fn()=>View::render(ROOT.'/app/Core/views/kid.php',['title'=>t('My day')],'lock'));
    }
    public static function familyItems():array{return self::query('SELECT f.*,p.name child_name FROM family_board f JOIN guardian_links g ON g.guardian_id=f.guardian_id AND g.child_id=f.child_id JOIN profiles p ON p.id=f.child_id WHERE f.guardian_id=? OR f.child_id=? ORDER BY f.done,f.due,f.id',[self::$current,self::$current])->fetchAll();}
    public static function familyAdd(string $child,string $title,string $due,string $pin):void{if(!self::canView($child))throw new InvalidArgumentException(t('No guardian permission for this child.'));self::verify(self::$current,$pin,true);valid_date($due);if(trim($title)===''||mb_strlen($title)>200)throw new InvalidArgumentException(t('Enter a chore title.'));self::query('INSERT INTO family_board(guardian_id,child_id,title,due) VALUES(?,?,?,?)',[self::$current,$child,trim($title),$due]);self::audit($child,'family-chore-created');}
    public static function familyDone(int $id):void{$item=null;foreach(self::familyItems()as $row)if((int)$row['id']===$id)$item=$row;if(!$item)throw new InvalidArgumentException(t('No access to this family chore.'));self::query('UPDATE family_board SET done=1 WHERE id=?',[$id]);self::audit($item['child_id'],'family-chore-completed');}
    public static function schoolReport(string $child,string $date):array{
        if(!self::canView($child))throw new InvalidArgumentException(t('No guardian permission for this profile.'));valid_date($date);$db=self::database($child);$start=(new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');$end=(new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d');$out=['start'=>$start,'end'=>$end,'completed'=>0,'reading_pages'=>0,'shared_moods'=>[]];
        $module=$db->query("SELECT enabled FROM modules WHERE module_id='School'")->fetchColumn();if($module!==false&&!(bool)$module)throw new InvalidArgumentException(t('School tools are disabled for this child.'));
        foreach($db->query("SELECT kind,data,status,completed_on FROM school_records WHERE status<>'archived'")as $row){$d=json_decode($row['data'],true);if($row['completed_on']!==null&&$row['completed_on']>=$start&&$row['completed_on']<=$end)$out['completed']++;$day=$d['date']??'';if($day<$start||$day>$end)continue;if($row['kind']==='reading')$out['reading_pages']+=(int)$d['pages'];if($row['kind']==='mood'&&$d['share']==='guardian')$out['shared_moods'][]=['date'=>$day,'mood'=>$d['mood']];}
        self::audit($child,'school-report-viewed');return $out;
    }
}
