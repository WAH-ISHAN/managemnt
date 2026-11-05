<?php
declare(strict_types=1);
/** Automatic paired-device snapshot delivery. Incoming replacement always needs review. */
final class WorkspaceExchange {
    private const MAGIC="LIFEOS-WORKSPACE-1\n";
    private const LIMIT=268435456;
    private const LOCAL_TABLES=['settings','migrations','modules','local_profiles','profile_roles','platform_peers','platform_publishers','platform_plugins','platform_audit','platform_workspace_receipts','connectivity_tokens','connectivity_macros'];
    public static function config(): array {
        return json_decode(Db::setting('workspace_exchange','{}'),true,16,JSON_THROW_ON_ERROR)+['enabled'=>false,'folder'=>'','peer'=>'','minutes'=>60];
    }
    private static function peer(string $id): array {
        return Db::one('SELECT * FROM platform_peers WHERE id=? AND revoked=0',[$id]) ?? throw new InvalidArgumentException(t('Pair this device first.'));
    }
    private static function folder(string $path): string {
        $real=realpath($path); $data=realpath(DATA);
        if (!$real || !is_dir($real) || !is_writable($real) || is_link($path)) throw new InvalidArgumentException(t('Choose an existing writable shared folder.'));
        $r=strtolower(str_replace('\\','/',$real)).'/'; $d=strtolower(str_replace('\\','/',$data)).'/';
        if (str_starts_with($r,$d) || str_starts_with($d,$r)) throw new InvalidArgumentException(t('Choose a shared folder outside the LifeOS data directory.'));
        return $real;
    }
    public static function configure(array $d): void {
        self::adult();
        if (!empty($d['enabled'])) {
            $peer=self::peer(is_string($d['peer']??null)?$d['peer']:'');
            $folder=self::folder(is_string($d['folder']??null)?trim($d['folder']):'');
            $minutes=int_range($d['minutes']??60,15,1440);
            $old=self::config();
            if (!$old['enabled'] || $old['peer']!==$peer['id'] || $old['folder']!==$folder) {
                if (($d['share_workspace']??'')!=='yes' || ($d['fingerprint']??'')!==$peer['id']) throw new InvalidArgumentException(t('Approve sharing this workspace and verify the other device fingerprint.'));
            }
            $c=['enabled'=>true,'folder'=>$folder,'peer'=>$peer['id'],'minutes'=>$minutes];
            if (!$old['enabled'] || $old['peer']!==$c['peer'] || $old['folder']!==$c['folder']) Db::set('workspace_exchange_revision','');
        } else $c=self::config()+[];
        if (empty($d['enabled'])) $c['enabled']=false;
        Db::set('workspace_exchange',json_encode($c,JSON_THROW_ON_ERROR));
        Db::set('workspace_exchange_attempt','0');
        PlatformService::audit('workspace-delivery-configured',$c['enabled']?'Enabled for paired device '.$c['peer']:'Paused');
    }
    private static function quote(string $name): string { return '"'.str_replace('"','""',$name).'"'; }
    private static function adult(): void { if (class_exists('ProfileManager') && ProfileManager::isKid()) throw new InvalidArgumentException(t('Workspace sharing is unavailable in kid mode.')); }
    private static function idle(): void {
        foreach ([['focus_sessions',"status='running'"],['work_timers','1=1'],['role_time_logs','ended_at IS NULL']] as [$table,$where]) if (Db::one('SELECT 1 FROM '.self::quote($table).' WHERE '.$where.' LIMIT 1')) throw new InvalidArgumentException(t('Stop running focus and work timers before sharing or replacing a workspace.'));
    }
    private static function incomingIdle(PDO $db): void {
        foreach ([['focus_sessions',"status='running'"],['work_timers','1=1'],['role_time_logs','ended_at IS NULL']] as [$table,$where]) if ($db->query('SELECT 1 FROM '.self::quote($table).' WHERE '.$where.' LIMIT 1')->fetchColumn()) throw new InvalidArgumentException(t('The received workspace has a running timer. Stop it on the other computer and deliver a new snapshot.'));
    }
    private static function excluded(string $table): bool {
        return in_array($table,self::LOCAL_TABLES,true) || str_starts_with($table,'platform_') || str_starts_with($table,'connectivity_') || str_starts_with($table,'automation_') || $table==='school_guardian_audit';
    }
    private static function pdo(string $file): PDO { return new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); }
    /** No settings, connection credentials, tokens, trusted code, device relationships or executable rules leave the device. */
    public static function sanitize(string $file): void {
        $db=self::pdo($file); $db->exec('PRAGMA foreign_keys=OFF');
        // Disable history/identity triggers during export, then restore their exact schema.
        $triggers=$db->query("SELECT name,sql FROM sqlite_master WHERE type='trigger'")->fetchAll();
        foreach ($triggers as $trigger) $db->exec('DROP TRIGGER '.self::quote($trigger['name']));
        foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) if (self::excluded($table) && $table!=='migrations') $db->exec('DELETE FROM '.self::quote($table));
        if ($db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='school_reward_requests'")->fetchColumn()) $db->exec('UPDATE school_reward_requests SET guardian_id=NULL');
        foreach ($triggers as $trigger) $db->exec($trigger['sql']);
        $db->exec('VACUUM');
        if ($db->query('PRAGMA foreign_key_check')->fetch()) throw new RuntimeException(t('This workspace contains a relationship that cannot be safely transferred.'));
    }
    private static function passphrase(array $header,array $peer): string {
        $me=DeviceSyncService::identity();
        $shared=sodium_crypto_scalarmult(sodium_crypto_box_secretkey($me['key']),base64_decode($peer['public_key'],true));
        return hash_hmac('sha256',self::MAGIC.json_encode($header,JSON_THROW_ON_ERROR),$shared);
    }
    private static function tables(PDO $db): array { return $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN); }
    private static function rows(PDO $db,string $table): array {
        $pk=[]; foreach ($db->query('PRAGMA table_info('.self::quote($table).')') as $column) if ($column['pk']) $pk[(int)$column['pk']]=$column['name']; ksort($pk);
        $rows=[];
        foreach ($db->query('SELECT * FROM '.self::quote($table)) as $row) {
            $json=json_encode($row,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
            $key=$pk?json_encode(array_map(fn($k)=>$row[$k],array_values($pk)),JSON_THROW_ON_ERROR):hash('sha256',$json);
            $rows[$key]=hash('sha256',$json);
        }
        ksort($rows); return $rows;
    }
    private static function uploads(string $root): array {
        $out=[]; if (!is_dir($root)) return $out;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) throw new InvalidArgumentException(t('Shared documents cannot contain symbolic links.'));
            if ($file->isFile()) $out[str_replace('\\','/',substr($file->getPathname(),strlen($root)+1))]=hash_file('sha256',$file->getPathname());
        }
        ksort($out); return $out;
    }
    public static function revision(): string {
        $out=[]; foreach (Db::all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $row) {
            $table=$row['name']; if (self::excluded($table)) continue;
            $rows=Db::all('SELECT * FROM '.self::quote($table)); $serialized=array_map(fn($r)=>json_encode($r,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),$rows); sort($serialized); $out[$table]=hash('sha256',implode("\n",$serialized));
        }
        $out['uploads']=self::uploads(DATA.'/uploads'); return hash('sha256',json_encode($out,JSON_THROW_ON_ERROR));
    }
    public static function send(): string {
        self::adult(); self::idle();
        $c=self::config(); if (!$c['enabled']) throw new InvalidArgumentException(t('Enable workspace delivery first.'));
        $folder=self::folder($c['folder']); $peer=self::peer($c['peer']); $me=DeviceSyncService::identity();
        $header=['version'=>1,'from'=>$me['id'],'to'=>$peer['id'],'id'=>bin2hex(random_bytes(16)),'created_at'=>gmdate(DATE_ATOM)];
        $revision=self::revision(); $archive=EncryptedBackupService::create(DATA,self::passphrase($header,$peer),self::sanitize(...));
        $name='workspace-'.$me['id'].'-'.$peer['id'].'-'.(new DateTimeImmutable($header['created_at']))->format('YmdHis').'-'.$header['id'].'.lifeosws'; $target=$folder.'/'.$name; $temp=$target.'.partial';
        try {
            $out=fopen($temp,'xb'); $in=fopen($archive,'rb'); if (!$out || !$in) throw new RuntimeException(t('Could not open the workspace delivery file.'));
            try { $head=json_encode($header,JSON_THROW_ON_ERROR); self::writeAll($out,self::MAGIC.pack('N',strlen($head)).$head); if (stream_copy_to_stream($in,$out)!==filesize($archive)) throw new RuntimeException(t('Workspace copy was incomplete.')); fflush($out); if (function_exists('fsync')) fsync($out); }
            finally { fclose($in); fclose($out); }
            // Validate delivered ciphertext using the exact length/hash of the source archive.
            $in=fopen($temp,'rb'); fseek($in,strlen(self::MAGIC)+4+strlen($head)); $ctx=hash_init('sha256'); hash_update_stream($ctx,$in); fclose($in);
            if (!hash_equals(hash_file('sha256',$archive),hash_final($ctx)) || !rename($temp,$target)) throw new RuntimeException(t('Workspace delivery could not be verified.'));
            Db::set('workspace_exchange_success',gmdate(DATE_ATOM)); Db::set('workspace_exchange_error','');
            Db::set('workspace_exchange_revision',$revision);
            PlatformService::audit('workspace-delivered',$peer['id'].'; '.$name);
            $old=[]; foreach (new DirectoryIterator($folder) as $entry) {
                if ($entry->getFilename()!==$name && $entry->isFile() && !$entry->isLink() && preg_match('/^workspace-'.preg_quote($me['id'],'/').'-'.preg_quote($peer['id'],'/').'-[0-9]{14}-[a-f0-9]{32}\.lifeosws$/D',$entry->getFilename())) $old[]=$entry->getPathname();
            }
            rsort($old); foreach(array_slice($old,2)as $previous) unlink($previous);
            return $name;
        } finally { if (is_file($archive)) unlink($archive); if (is_file($temp)) unlink($temp); }
    }
    private static function writeAll($out,string $bytes): void { while ($bytes!=='') { $n=fwrite($out,$bytes); if ($n===false || $n===0) throw new RuntimeException(t('Could not write the workspace delivery.')); $bytes=substr($bytes,$n); } }
    private static function path(string $name): string {
        if (!preg_match('/^workspace-[a-f0-9]{64}-[a-f0-9]{64}-[0-9]{14}-[a-f0-9]{32}\.lifeosws$/D',$name)) throw new InvalidArgumentException(t('Choose a received workspace snapshot.'));
        $path=self::folder(self::config()['folder']).'/'.$name;
        if (!is_file($path) || is_link($path) || filesize($path)>self::LIMIT+4096) throw new InvalidArgumentException(t('Workspace delivery is missing or too large.'));
        return $path;
    }
    public static function incoming(): array {
        $c=self::config(); if ($c['folder']==='') return []; $folder=self::folder($c['folder']); $me=DeviceSyncService::identity(); $out=[]; $seen=0;
        foreach (new DirectoryIterator($folder) as $entry) {
            if (++$seen>5000) break; $name=$entry->getFilename();
            if (!preg_match('/^workspace-([a-f0-9]{64})-'.preg_quote($me['id'],'/').'-[0-9]{14}-[a-f0-9]{32}\.lifeosws$/D',$name,$m) || !$entry->isFile() || $entry->isLink()) continue;
            $peer=Db::one('SELECT name FROM platform_peers WHERE id=? AND revoked=0',[$m[1]]); if (!$peer) continue;
            $out[]=['file'=>$name,'peer'=>$peer['name'],'bytes'=>$entry->getSize()];
        }
        usort($out,fn($a,$b)=>strcmp($b['file'],$a['file'])); return array_slice($out,0,50);
    }
    private static function open(string $name,callable $reader): mixed {
        $path=self::path($name); $in=fopen($path,'rb'); $temp=tempnam(DATA,'workspace-received-');
        try {
            if (fread($in,strlen(self::MAGIC))!==self::MAGIC) throw new InvalidArgumentException(t('Choose an authenticated workspace snapshot.'));
            $length=fread($in,4); if (strlen($length)!==4 || ($length=unpack('N',$length)[1])<1 || $length>2048) throw new InvalidArgumentException(t('Invalid workspace delivery header.'));
            $raw=fread($in,$length); if (strlen($raw)!==$length) throw new InvalidArgumentException(t('Incomplete workspace delivery.'));
            $header=json_decode($raw,true,8,JSON_THROW_ON_ERROR); $me=DeviceSyncService::identity();
            if (!is_array($header) || ($header['version']??null)!==1 || ($header['to']??'')!==$me['id'] || !is_string($header['from']??null) || !preg_match('/^[a-f0-9]{64}$/D',$header['from']) || !is_string($header['id']??null) || !preg_match('/^[a-f0-9]{32}$/D',$header['id']) || !is_string($header['created_at']??null)) throw new InvalidArgumentException(t('Workspace delivery is not for this device.'));
            try { $created=new DateTimeImmutable($header['created_at']); } catch(Throwable) { throw new InvalidArgumentException(t('Invalid workspace delivery date.')); }
            $expected='workspace-'.$header['from'].'-'.$header['to'].'-'.$created->setTimezone(new DateTimeZone('UTC'))->format('YmdHis').'-'.$header['id'].'.lifeosws';
            if ($name!==$expected) throw new InvalidArgumentException(t('Workspace delivery name does not match its authenticated sender.'));
            $peer=self::peer($header['from']??'');
            $out=fopen($temp,'wb'); try { stream_copy_to_stream($in,$out,self::LIMIT+1); } finally { fclose($out); }
            if (filesize($temp)>self::LIMIT) throw new InvalidArgumentException(t('Workspace delivery exceeds 256 MiB.'));
            return $reader($temp,self::passphrase($header,$peer),$header,hash_file('sha256',$path));
        } finally { fclose($in); if (is_file($temp)) unlink($temp); }
    }
    private static function dirs(): array { return array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',Modules::discover(ROOT.'/app/Modules'))); }
    private static function diff(array $before,array $after): array {
        $changed=0; foreach (array_intersect_key($before,$after) as $id=>$hash) if ($hash!==$after[$id]) $changed++;
        return ['added'=>count(array_diff_key($after,$before)),'changed'=>$changed,'removed'=>count(array_diff_key($before,$after))];
    }
    public static function preview(string $name): array {
        self::adult(); self::idle();
        return self::open($name,function($archive,$pass,$header,$hash) use($name) {
            if (Db::one('SELECT hash FROM platform_workspace_receipts WHERE hash=?',[$hash])) throw new InvalidArgumentException(t('This workspace snapshot was already applied.'));
            $revision=self::revision();
            $tables=EncryptedBackupService::inspect($archive,$pass,DATA,self::dirs(),function($file,$uploads) {
                $incoming=self::pdo($file); self::incomingIdle($incoming); $local=self::pdo(DATA.'/lifeos.db'); $rows=[];
                foreach (self::tables($incoming) as $table) if (!self::excluded($table)) { $diff=self::diff(self::rows($local,$table),self::rows($incoming,$table)); if (array_sum($diff)>0) $rows[]=['table'=>$table]+$diff; }
                $rows[]=['table'=>'Document files']+self::diff(self::uploads(DATA.'/uploads'),self::uploads($uploads)); return $rows;
            });
            return ['file'=>$name,'hash'=>$hash,'revision'=>$revision,'from'=>$header['from'],'sender_name'=>self::peer($header['from'])['name'],'created_at'=>$header['created_at'],'expires'=>time()+900,'changes'=>$tables];
        });
    }
    private static function retainLocal(string $file): void {
        $incoming=self::pdo($file); self::incomingIdle($incoming); $incoming->exec('PRAGMA foreign_keys=OFF');
        $triggers=$incoming->query("SELECT name,sql FROM sqlite_master WHERE type='trigger'")->fetchAll(); foreach ($triggers as $trigger) $incoming->exec('DROP TRIGGER '.self::quote($trigger['name']));
        foreach (self::tables($incoming) as $table) if (self::excluded($table) && $table!=='migrations') {
            $incoming->exec('DELETE FROM '.self::quote($table));
            if (!in_array($table,self::LOCAL_TABLES,true) && !str_starts_with($table,'automation_')) continue;
            foreach (Db::all('SELECT * FROM '.self::quote($table)) as $row) { $stmt=$incoming->prepare('INSERT INTO '.self::quote($table).' ('.implode(',',array_map(self::quote(...),array_keys($row))).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')'); $stmt->execute(array_values($row)); }
        }
        // Numeric record identities now belong to the incoming workspace. Keep
        // local approvals/settings, but require a fresh review before any job
        // can act on them or forward the newly received private records.
        $incoming->exec('UPDATE automation_rules SET enabled=0; UPDATE connectivity_macros SET enabled=0; UPDATE financial_recurrences SET active=0');
        $expire=$incoming->prepare('UPDATE connectivity_tokens SET expires_at=?'); $expire->execute([gmdate('Y-m-d H:i:s')]);
        $setting=$incoming->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
        foreach (['planning_enabled'=>'0','planning_reschedule'=>'0','planning_archive_days'=>'0','uni_calendar_enabled'=>'0','workspace_exchange_revision'=>''] as $key=>$value) $setting->execute([$key,$value]);
        $connections=json_decode(Db::setting('connections_config','{}'),true,32,JSON_THROW_ON_ERROR);
        foreach (['calendar_enabled','calendar_push','calendar_delete','mail_enabled','tracking_enabled'] as $key) $connections[$key]=false;
        $setting->execute(['connections_config',json_encode($connections,JSON_THROW_ON_ERROR)]);
        $exchange=self::config(); $exchange['enabled']=false; $setting->execute(['workspace_exchange',json_encode($exchange,JSON_THROW_ON_ERROR)]);
        foreach ($triggers as $trigger) $incoming->exec($trigger['sql']);
    }
    /** These markers must activate with the records, even if the process stops
     * immediately after the database switch and before the HTTP response. */
    private static function prepareReplacement(string $file,string $hash,string $peer,string $generation): void {
        self::retainLocal($file); $incoming=self::pdo($file); $incoming->beginTransaction();
        try {
            $setting=$incoming->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value'); $setting->execute(['workspace_generation',$generation]);
            $receipt=$incoming->prepare('INSERT INTO platform_workspace_receipts(hash,peer) VALUES(?,?)'); $receipt->execute([$hash,$peer]);
            $incoming->commit();
        } catch(Throwable $error) { if($incoming->inTransaction())$incoming->rollBack(); throw $error; }
    }
    public static function apply(array $review,string $confirmation): string {
        self::adult(); self::idle();
        if ($confirmation!=='REPLACE' || ($review['expires']??0)<time() || !is_string($review['revision']??null)) throw new InvalidArgumentException(t('Review the snapshot again and type REPLACE to confirm.'));
        return self::open($review['file'],function($archive,$pass,$header,$hash) use($review) {
            if (!hash_equals($review['hash'],$hash) || !hash_equals($review['revision'],self::revision())) throw new InvalidArgumentException(t('The workspace or incoming file changed after review. Preview it again.'));
            if (Db::one('SELECT hash FROM platform_workspace_receipts WHERE hash=?',[$hash])) throw new InvalidArgumentException(t('This workspace snapshot was already applied.'));
            $generation=bin2hex(random_bytes(32));
            $backup=EncryptedBackupService::restore($archive,$pass,DATA,self::dirs(),function($file) use($review,$hash,$header,$generation) { if (!hash_equals($review['revision'],self::revision())) throw new InvalidArgumentException(t('The workspace changed after review. Preview it again.')); self::prepareReplacement($file,$hash,$header['from'],$generation); });
            if (session_status()===PHP_SESSION_ACTIVE) { $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); $_SESSION['workspace_generation']=$generation; }
            PlatformService::audit('workspace-replaced',$header['from'].'; previous database/documents: '.$backup);
            return $backup;
        });
    }
    public static function tick(): array {
        $result=['ran'=>0,'failed'=>0]; if (!module_enabled('Platform')) return $result; $c=self::config();
        if (!$c['enabled'] || time()-(int)Db::setting('workspace_exchange_attempt','0')<$c['minutes']*60) return $result;
        $lock=fopen(DATA.'/.workspace-exchange.lock','c+b'); if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); return $result; }
        try {
            Db::set('workspace_exchange_attempt',(string)time());
            if (!hash_equals(Db::setting('workspace_exchange_revision'),self::revision())) { self::send(); $result['ran']=1; }
        } catch (Throwable $e) { Db::set('workspace_exchange_error',mb_substr($e->getMessage(),0,500)); $result['failed']=1; }
        finally { flock($lock,LOCK_UN); fclose($lock); }
        return $result;
    }
}
