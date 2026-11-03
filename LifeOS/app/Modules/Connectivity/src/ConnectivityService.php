<?php
declare(strict_types=1);
final class ConnectivityService {
    public static function config(): array {
        return json_decode(Db::setting('connections_config','{}'),true,32,JSON_THROW_ON_ERROR)+['backup_enabled'=>false,'backup_destination'=>'','backup_time'=>'20:00','backup_keep'=>14,'mail_enabled'=>false,'mail_host'=>'','mail_port'=>993,'mail_user'=>'','mail_folder'=>'INBOX','mail_sender'=>'','mail_subject'=>'[task]','mail_interval'=>15,'python'=>'','tracking_enabled'=>false,'tracking_exclusions'=>'KeePass,1Password,Bitwarden','tracking_retention'=>30,'calendar_enabled'=>false,'calendar_provider'=>'google','calendar_id'=>'primary','calendar_client_id'=>'','calendar_tenant'=>'common','calendar_push'=>false];
    }
    public static function saveConfig(array $d): void {
        $old=self::config(); $c=$old;
        foreach (['backup_enabled','mail_enabled','tracking_enabled','calendar_enabled','calendar_push'] as $k) $c[$k]=!empty($d[$k]);
        foreach (['backup_destination','mail_host','mail_user','mail_folder','mail_sender','mail_subject','python','tracking_exclusions','calendar_id','calendar_client_id','calendar_tenant'] as $k) { $v=$d[$k] ?? ''; if (!is_string($v) || strlen($v)>1000 || preg_match('/[\r\n\x00]/',$v)) throw new InvalidArgumentException(t('Invalid connection setting.')); $c[$k]=trim($v); }
        $c['backup_time']=PlannerService::validTime($d['backup_time'] ?? '20:00');
        foreach (['backup_keep'=>[2,90],'mail_port'=>[1,65535],'mail_interval'=>[5,1440],'tracking_retention'=>[1,365]] as $k=>$bounds) $c[$k]=int_range($d[$k] ?? $old[$k],...$bounds);
        $provider=$d['calendar_provider'] ?? 'google'; if (!in_array($provider,['google','outlook'],true)) throw new InvalidArgumentException(t('Choose Google or Outlook.')); $c['calendar_provider']=$provider;
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D',$c['calendar_tenant'])) throw new InvalidArgumentException(t('Enter a valid Microsoft tenant ID or common.'));
        if ($c['mail_enabled'] && (!preg_match('/^[a-zA-Z0-9.-]+$/D',$c['mail_host']) || $c['mail_user']==='' || $c['mail_subject']==='' || $c['python']==='')) throw new InvalidArgumentException(t('Email polling needs a TLS IMAP host, username, subject filter and Python executable.'));
        if ($c['python']!=='' && (!is_file($c['python']) || !preg_match('~(?:^|[/\\\\])python(?:3(?:\.\d+)?)?(?:\.exe)?$~iD',$c['python']))) throw new InvalidArgumentException(t('Choose an absolute Python executable path.'));
        if ($c['backup_enabled']) {
            self::destination($c['backup_destination']);
            if (strlen((string)($d['backup_passphrase'] ?? ''))<12 && ConnectivitySecrets::get('backup_passphrase')==='') throw new InvalidArgumentException(t('Scheduled backup needs a recovery passphrase of at least 12 characters.'));
        }
        if ($c['calendar_provider']!==$old['calendar_provider'] || $c['calendar_client_id']!==$old['calendar_client_id']) {
            foreach (['calendar_access_token','calendar_refresh_token','calendar_client_secret'] as $secret) ConnectivitySecrets::forget($secret);
            Db::set('connection_calendar_token_expiry','0');
        }
        foreach (['backup_passphrase','mail_password','calendar_access_token','calendar_refresh_token','calendar_client_secret'] as $key) {
            $v=$d[$key] ?? ''; if (!is_string($v)) throw new InvalidArgumentException(t('Enter a valid connection secret.'));
            if ($key==='backup_passphrase' && $v!=='' && mb_strlen($v)<12) throw new InvalidArgumentException(t('Use at least 12 characters for the recovery passphrase.'));
            ConnectivitySecrets::save($key,$v);
        }
        Db::set('connections_config',json_encode($c,JSON_THROW_ON_ERROR));
        Db::run('DELETE FROM connectivity_window_time WHERE recorded_at<?',[gmdate('Y-m-d H:i:s',time()-$c['tracking_retention']*86400)]);
        if (Db::setting('connection_calendar_identity')==='') Db::set('connection_calendar_identity',bin2hex(random_bytes(16)));
    }
    private static function destination(string $path): string {
        $real=realpath($path); if (!$real || !is_dir($real) || !is_writable($real)) throw new InvalidArgumentException(t('Connect the external disk or choose an existing writable backup folder.'));
        $data=realpath(DATA); $r=strtolower(str_replace('\\','/',$real).'/'); $d=strtolower(str_replace('\\','/',$data ?: DATA).'/');
        if (str_starts_with($r,$d)) throw new InvalidArgumentException(t('Choose a backup destination outside this profile data folder.'));
        return $real;
    }
    public static function log(string $kind,string $status,string $detail): void { Db::run('INSERT INTO connectivity_runs(kind,status,detail) VALUES (?,?,?)',[$kind,$status,mb_substr($detail,0,2000)]); }
    public static function backup(): string {
        $c=self::config(); if (!$c['backup_enabled']) throw new InvalidArgumentException(t('Enable scheduled external backups first.'));
        $dest=self::destination($c['backup_destination']); $pass=ConnectivitySecrets::get('backup_passphrase');
        $source=EncryptedBackupService::create(DATA,$pass);
        $identity=substr(hash('sha256',realpath(DATA) ?: DATA),0,12);
        $target=$dest.'/lifeos-'.$identity.'-'.basename($source); $partial=$target.'.partial';
        try {
            if (!copy($source,$partial) || !hash_equals(hash_file('sha256',$source),hash_file('sha256',$partial)) || !rename($partial,$target)) throw new RuntimeException(t('The external backup copy could not be verified. Check the disk and free space.'));
            $files=glob($dest.'/lifeos-'.$identity.'-lifeos-encrypted-*.lifeosbk') ?: []; rsort($files);
            foreach (array_slice($files,$c['backup_keep']) as $file) if (is_file($file) && !is_link($file)) unlink($file);
            Db::set('connection_backup_success',date(DATE_ATOM)); self::log('backup','complete','Verified encrypted backup: '.basename($target)); return $target;
        } finally { if (is_file($partial)) unlink($partial); }
    }
    public static function token(string $name,array $scopes,int $days=90): string {
        $name=PlannerService::title($name,100); if (!$scopes || array_diff($scopes,['tasks.read','tasks.write','time.write'])) throw new InvalidArgumentException(t('Choose supported API permissions.'));
        $token=bin2hex(random_bytes(32)); Db::run('INSERT INTO connectivity_tokens(name,hash,scopes,expires_at) VALUES (?,?,?,?)',[$name,hash('sha256',$token),json_encode(array_values(array_unique($scopes))),gmdate('Y-m-d H:i:s',time()+int_range($days,1,365)*86400)]); return $token;
    }
    /** Separate scoped API authentication. Browser cookies do not authorize this endpoint. */
    public static function authorizeApi(?string $scope=null): array {
        if (isset($_SERVER['HTTP_ORIGIN'])) throw new InvalidArgumentException(t('Browser origins are not permitted on the local API.'));
        $peer=$_SERVER['REMOTE_ADDR'] ?? ''; if (!in_array($peer,['127.0.0.1','::1'],true)) throw new InvalidArgumentException(t('The API is loopback only.'));
        if (!module_enabled('Connectivity')) throw new InvalidArgumentException(t('Connections module is switched off.'));
        $auth=$_SERVER['HTTP_AUTHORIZATION'] ?? ''; if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m)) throw new InvalidArgumentException(t('A scoped bearer token is required.'));
        $token=Db::one('SELECT * FROM connectivity_tokens WHERE hash=? AND expires_at>?',[hash('sha256',$m[1]),gmdate('Y-m-d H:i:s')]);
        if (!$token || ($scope && !in_array($scope,json_decode($token['scopes'],true),true))) throw new InvalidArgumentException(t('Token expired, revoked or missing permission.'));
        return $token;
    }
    public static function api(string $method,string $path,array $data=[]): array {
        if ($method==='GET' && $path==='/api/v1/tasks') { self::authorizeApi('tasks.read'); return ['tasks'=>Db::all('SELECT id,title,due,status,completed_at FROM tasks ORDER BY id DESC LIMIT 200')]; }
        if ($method==='POST' && $path==='/api/v1/tasks') { self::authorizeApi('tasks.write'); if (!module_enabled('Planner')) throw new InvalidArgumentException(t('Planner is switched off.')); return ['id'=>PlannerService::saveTask(null,$data)]; }
        if ($method==='POST' && preg_match('~^/api/v1/tasks/(\d+)/complete$~D',$path,$m)) { self::authorizeApi('tasks.write'); if (!module_enabled('Planner')) throw new InvalidArgumentException(t('Planner is switched off.')); PlannerService::taskStatus((int)$m[1],'done'); return ['ok'=>true]; }
        if ($method==='POST' && $path==='/api/v1/window-time') { self::authorizeApi('time.write'); return self::windowTime($data); }
        throw new InvalidArgumentException(t('Unsupported API method or resource.'));
    }
    public static function windowTime(array $d): array {
        $c=self::config(); if (!$c['tracking_enabled']) throw new InvalidArgumentException(t('Active-window tracking is paused.'));
        $process=PlannerService::title($d['process'] ?? '',100); $category=PlannerService::title($d['category'] ?? 'Uncategorized',100); $identity=PlannerService::title($d['identity'] ?? '',200); $seconds=int_range($d['seconds'] ?? 0,1,300);
        foreach (explode(',',$c['tracking_exclusions']) as $exclude) if (trim($exclude)!=='' && stripos($process,trim($exclude))!==false) return ['saved'=>false,'excluded'=>true];
        // Window titles are intentionally not collected.
        Db::run('INSERT OR IGNORE INTO connectivity_window_time(recorded_at,process,category,seconds,identity) VALUES (?,?,?,?,?)',[gmdate('Y-m-d H:i:s'),$process,$category,$seconds,$identity]);
        Db::run('DELETE FROM connectivity_window_time WHERE recorded_at<?',[gmdate('Y-m-d H:i:s',time()-$c['tracking_retention']*86400)]); return ['saved'=>true];
    }
    public static function worker(string $operation,array $input): array {
        $python=self::config()['python']; if ($python==='') throw new InvalidArgumentException(t('Configure Python 3 to use this local adapter.'));
        $result=self::process([$python,ROOT.'/scripts/connectivity_worker.py',$operation],json_encode($input,JSON_THROW_ON_ERROR),25);
        $decoded=json_decode($result,true,32,JSON_THROW_ON_ERROR); if (!is_array($decoded) || isset($decoded['error'])) throw new RuntimeException(t('Local adapter failed. Check host, permissions and credentials in Connections.')); return $decoded;
    }
    public static function pollMail(): int {
        $c=self::config(); if (!$c['mail_enabled']) throw new InvalidArgumentException(t('Enable filtered email polling first.'));
        $rows=self::worker('imap',['host'=>$c['mail_host'],'port'=>$c['mail_port'],'user'=>$c['mail_user'],'password'=>ConnectivitySecrets::get('mail_password'),'folder'=>$c['mail_folder'],'sender'=>$c['mail_sender'],'subject'=>$c['mail_subject']])['messages'] ?? [];
        $count=0;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['identity'],$row['subject'],$row['sender'],$row['body'])) throw new RuntimeException(t('Invalid email adapter response.'));
            if (Db::one('SELECT id FROM connectivity_mail WHERE identity=?',[$row['identity']])) continue;
            Db::run('INSERT INTO connectivity_mail(identity,sender,subject,body,received_at) VALUES (?,?,?,?,?)',[$row['identity'],mb_substr($row['sender'],0,500),mb_substr($row['subject'],0,200),mb_substr($row['body'],0,5000),gmdate('Y-m-d H:i:s')]); $count++;
        }
        self::log('mail','complete',$count.' new matching email preview(s). No messages marked read or deleted.'); return $count;
    }
    public static function acceptMail(int $id): int {
        if (!module_enabled('Planner')) throw new InvalidArgumentException(t('Enable Planner to create email tasks.'));
        return Db::tx(function () use ($id) {
            $row=Db::one('SELECT * FROM connectivity_mail WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException(t('Email preview not found.'));
            if ($row['task_id']) return (int)$row['task_id']; if ($row['state']!=='preview') throw new InvalidArgumentException(t('This email has been ignored.'));
            $task=PlannerService::saveTask(null,['title'=>$row['subject']]); Db::run("UPDATE connectivity_mail SET task_id=?,state='accepted' WHERE id=?",[$task,$id]); return $task;
        });
    }
    public static function saveMacro(array $d): int {
        $name=PlannerService::title($d['name'] ?? '',100); $time=PlannerService::validTime($d['run_at'] ?? '09:00');
        $raw=$d['actions'] ?? ''; if (!is_string($raw) || strlen($raw)>20000) throw new InvalidArgumentException(t('Macro actions must be a short JSON list.'));
        try { $actions=json_decode($raw,true,16,JSON_THROW_ON_ERROR); } catch (Throwable) { throw new InvalidArgumentException(t('Enter valid macro JSON.')); }
        if (!is_array($actions) || !array_is_list($actions) || !$actions || count($actions)>10) throw new InvalidArgumentException(t('Choose one to ten macro actions.'));
        $clean=[];
        foreach ($actions as $a) {
            if (!is_array($a)) throw new InvalidArgumentException(t('Invalid macro action.'));
            if (($a['type'] ?? '')==='task') $clean[]=['type'=>'task','title'=>PlannerService::title($a['title'] ?? ''),'due_days'=>int_range($a['due_days'] ?? 0,0,365)];
            elseif (($a['type'] ?? '')==='script') {
                if (empty($d['trust_scripts'])) throw new InvalidArgumentException(t('Approve the script explicitly before saving this macro.'));
                $file=is_string($a['path'] ?? null) ? realpath($a['path']) : false;
                if (!$file || !is_file($file) || is_link($a['path']) || !in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),['py','php'],true)) throw new InvalidArgumentException(t('Choose an existing .py or .php script.'));
                if (filesize($file)>1048576) throw new InvalidArgumentException(t('Script must be smaller than 1 MiB.'));
                $clean[]=['type'=>'script','path'=>$file,'sha256'=>hash_file('sha256',$file)];
            } else throw new InvalidArgumentException(t('Supported macro actions are task and script.'));
        }
        return Db::run('INSERT INTO connectivity_macros(name,actions,enabled,run_at) VALUES (?,?,?,?)',[$name,json_encode($clean),!empty($d['enabled']) ? 1 : 0,$time]);
    }
    public static function runMacro(int $id,bool $scheduled=false): void {
        $m=Db::one('SELECT * FROM connectivity_macros WHERE id=?',[$id]); if (!$m) throw new InvalidArgumentException(t('Macro not found.'));
        $slot=$scheduled ? date('Y-m-d') : 'manual-'.bin2hex(random_bytes(8));
        if (Db::one('SELECT id FROM connectivity_script_runs WHERE macro_id=? AND slot=?',[$id,$slot])) return;
        $run=Db::run("INSERT INTO connectivity_script_runs(macro_id,slot,status) VALUES (?,?,'running')",[$id,$slot]);
        try {
            $deadline=microtime(true)+20;
            foreach (json_decode($m['actions'],true,16,JSON_THROW_ON_ERROR) as $a) {
                if (microtime(true)>=$deadline) throw new RuntimeException(t('Macro exceeded its total 20-second run budget.'));
                if ($a['type']==='task') { if (!module_enabled('Planner')) throw new RuntimeException(t('Planner is switched off.')); PlannerService::saveTask(null,['title'=>$a['title'],'due'=>date('Y-m-d',strtotime('+'.$a['due_days'].' days'))]); }
                else {
                    if (!is_file($a['path']) || !hash_equals($a['sha256'],hash_file('sha256',$a['path']))) throw new RuntimeException(t('Script changed since approval. Remove and recreate this macro to approve the new contents.'));
                    $exe=str_ends_with(strtolower($a['path']),'.php') ? PHP_BINARY : self::config()['python'];
                    if ($exe==='') throw new RuntimeException(t('Configure Python before running Python scripts.'));
                    self::process([$exe,$a['path']],'',max(1,(int)ceil($deadline-microtime(true))));
                }
            }
            Db::run("UPDATE connectivity_script_runs SET status='complete',detail='All actions finished.' WHERE id=?",[$run]);
        } catch (Throwable $e) { Db::run("UPDATE connectivity_script_runs SET status='failed',detail=? WHERE id=?",[mb_substr($e->getMessage(),0,1000),$run]); throw $e; }
    }
    private static function process(array $command,string $input,int $timeout): string {
        $environment=getenv(); $environment['LIFEOS_DATA_DIR']=DATA;
        $environment['LIFEOS_PROFILE_ID']=class_exists('ProfileManager') ? ProfileManager::id() : 'default';
        $pipes=[]; $p=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,ROOT,$environment,['bypass_shell'=>true]);
        if (!is_resource($p)) throw new RuntimeException(t('Could not start the local adapter.'));
        fwrite($pipes[0],$input); fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false); $start=microtime(true); $out=''; $err=''; $exit=-1;
        try {
            do {
                $out.=stream_get_contents($pipes[1]); $err.=stream_get_contents($pipes[2]);
                if (strlen($out)+strlen($err)>131072) throw new RuntimeException(t('Local process exceeded its output limit.'));
                $state=proc_get_status($p); if (!$state['running']) { $exit=$state['exitcode']; break; }
                if (microtime(true)-$start>$timeout) throw new RuntimeException(t('Local process exceeded its run limit.'));
                usleep(50000);
            } while (true);
            $out.=stream_get_contents($pipes[1]); if ($exit!==0) throw new RuntimeException(t('Local adapter exited unsuccessfully. Check its configuration.')); return $out;
        } finally { $state=proc_get_status($p); if ($state['running']) proc_terminate($p); foreach ([1,2] as $i) fclose($pipes[$i]); proc_close($p); }
    }
    /** Called by the resident bridge after ordinary automation commits, never inside its transaction. */
    public static function tick(): array {
        if (!module_enabled('Connectivity')) return ['ran'=>0,'failed'=>0]; $c=self::config(); $ran=0; $failed=0;
        $lock=fopen(DATA.'/.connections-tick.lock','c+b'); if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); return ['ran'=>0,'failed'=>0]; }
        try {
            $jobs=[];
            if ($c['backup_enabled'] && date('H:i')>=$c['backup_time'] && Db::setting('connection_backup_slot')!==date('Y-m-d')) $jobs['backup']=fn()=>self::backup();
            if ($c['mail_enabled'] && time()-(int)Db::setting('connection_mail_tick','0')>=$c['mail_interval']*60) $jobs['mail']=fn()=>self::pollMail();
            if ($c['calendar_enabled'] && time()-(int)Db::setting('connection_calendar_tick','0')>=900) $jobs['calendar']=fn()=>ConnectivityCalendar::sync();
            foreach (array_slice($jobs,0,1,true) as $kind=>$job) {
                // Persist attempt time before network/IO; no tight failure retry loop.
                if ($kind==='backup') Db::set('connection_backup_slot',date('Y-m-d')); else Db::set('connection_'.$kind.'_tick',(string)time());
                try { $job(); $ran++; } catch (Throwable $e) { self::log($kind,'failed',$e->getMessage()); $failed++; }
            }
            foreach ($jobs ? [] : Db::all("SELECT m.id FROM connectivity_macros m WHERE m.enabled=1 AND m.run_at<=? AND NOT EXISTS (SELECT 1 FROM connectivity_script_runs r WHERE r.macro_id=m.id AND r.slot=?) ORDER BY m.id LIMIT 1",[date('H:i'),date('Y-m-d')]) as $row) {
                try { self::runMacro((int)$row['id'],true); } catch (Throwable $e) { self::log('macro','failed',$e->getMessage()); $failed++; }
            }
            return ['ran'=>$ran,'failed'=>$failed];
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
