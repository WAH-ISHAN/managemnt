<?php
declare(strict_types=1);
/** Versioned, streaming authenticated backups. The live SQLite file is not encrypted. */
final class EncryptedBackupService {
    private const MAGIC="LIFEOS-E1\n";
    private const ITERATIONS=600000;
    private const CHUNK=1048576;
    private const MAX_ENCRYPTED=256*1024*1024;
    private const MAX_ZIP=240*1024*1024;
    private const MAX_EXPANDED=512*1024*1024;
    private const MAX_FILES=5000;
    public static function create(string $data,string $passphrase): string {
        self::requirements(); self::passphrase($passphrase); $lock=self::lock($data);
        $stage=$data.'/.backup-stage-'.bin2hex(random_bytes(8)); self::mkdir($stage);
        $destination=$data.'/backups/lifeos-encrypted-'.date('Y-m-d-His').'-'.bin2hex(random_bytes(4)).'.lifeosbk';
        try {
            self::mkdir($data.'/backups');
            $zip=$stage.'/content.zip'; self::pack($data,$stage,$zip);
            self::encrypt($zip,$destination,$passphrase);
            return $destination;
        } catch (Throwable $e) { if (is_file($destination)) @unlink($destination); throw $e; }
        finally { self::cleanTree($stage,$data); flock($lock,LOCK_UN); fclose($lock); }
    }
    public static function restore(string $encrypted,string $passphrase,string $data,array $migrationDirs): string {
        self::requirements(); self::passphrase($passphrase); $lock=self::lock($data);
        $stageName='.restore-stage-'.bin2hex(random_bytes(8)); $stage=$data.'/'.$stageName; self::mkdir($stage);
        $journal=$data.'/restore-journal.json'; $safetyName='before-encrypted-restore-'.date('Y-m-d-His').'-'.bin2hex(random_bytes(4)); $safety=$data.'/backups/'.$safetyName;
        $mutating=false;
        try {
            if (is_file($journal)) throw new RuntimeException('An interrupted restore needs recovery before another restore can start. Restart LifeOS.');
            $zip=$stage.'/content.zip'; self::decrypt($encrypted,$zip,$passphrase);
            self::unpack($zip,$stage.'/incoming');
            Backup::validate($stage.'/incoming/database.db',$migrationDirs);
            self::verifyDocumentReferences($stage.'/incoming/database.db',$stage.'/incoming/uploads');
            self::mkdir($safety);
            Db::run('VACUUM INTO ?',[$safety.'/database.db']);
            Db::exec('PRAGMA wal_checkpoint(TRUNCATE)'); Db::close();
            self::writeJournal($journal,['version'=>1,'stage'=>$stageName,'safety'=>$safetyName]);
            $mutating=true;
            self::mkdir($data.'/uploads');
            if (!rename($data.'/uploads',$safety.'/uploads')) throw new RuntimeException('Could not preserve existing documents. Close programs using uploaded files and try again.');
            if (!rename($stage.'/incoming/uploads',$data.'/uploads')) throw new RuntimeException('Could not activate the restored documents.');
            self::replaceDatabase($stage.'/incoming/database.db',$data.'/lifeos.db');
            Db::init($data.'/lifeos.db');
            if (!unlink($journal)) throw new RuntimeException('Could not finalize the restore journal.');
            $mutating=false;
            return $safetyName;
        } catch (Throwable $e) {
            if ($mutating) {
                Db::close();
                try { self::recoverPending($data,false); }
                catch (Throwable $recovery) { throw new RuntimeException('Restore was interrupted. Restart LifeOS to recover. Your previous database and documents are in data/backups/'.$safetyName.'.',0,$recovery); }
                finally { Db::init($data.'/lifeos.db'); }
            } else {
                // Validation failures occur before opening/closing or changing the live database.
                if (is_file($data.'/lifeos.db')) Db::init($data.'/lifeos.db');
            }
            throw $e;
        } finally {
            if (!is_file($journal)) self::cleanTree($stage,$data);
            flock($lock,LOCK_UN); fclose($lock);
        }
    }
    /** Call before Db::init during startup. A pending journal always rolls back both database and uploads. */
    public static function recoverPending(string $data,bool $withLock=true): void {
        $journal=$data.'/restore-journal.json'; if (!is_file($journal)) return;
        $lock=$withLock ? self::lock($data) : null;
        try {
            $raw=file_get_contents($journal,false,null,0,4096);
            $state=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            if (($state['version'] ?? null)!==1 || !preg_match('/^\.restore-stage-[a-f0-9]{16}$/D',$state['stage'] ?? '') || !preg_match('/^before-encrypted-restore-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]{8}$/D',$state['safety'] ?? '')) throw new RuntimeException('Invalid restore recovery journal.');
            $safety=$data.'/backups/'.$state['safety']; $stage=$data.'/'.$state['stage'];
            if (is_link($safety) || is_link($safety.'/database.db') || !is_file($safety.'/database.db')) throw new RuntimeException('The previous database snapshot is missing.');
            if (is_dir($safety.'/uploads')) {
                if (is_dir($data.'/uploads')) self::cleanTree($data.'/uploads',$data);
                if (!rename($safety.'/uploads',$data.'/uploads')) throw new RuntimeException('Could not recover the previous documents.');
            }
            self::replaceDatabase($safety.'/database.db',$data.'/lifeos.db');
            if (!unlink($journal)) throw new RuntimeException('Could not complete restore recovery.');
            self::cleanTree($stage,$data);
        } finally { if ($lock) { flock($lock,LOCK_UN); fclose($lock); } }
    }
    private static function requirements(): void {
        if (!extension_loaded('openssl') || !class_exists('ZipArchive')) throw new RuntimeException('Encrypted backups require the PHP openssl and zip extensions.');
        if (!class_exists('DocumentService')) throw new InvalidArgumentException('Enable the Documents module to use complete encrypted backups.');
    }
    private static function passphrase(string $passphrase): void {
        if (mb_strlen($passphrase)<12 || strlen($passphrase)>1024) throw new InvalidArgumentException('Use a backup passphrase of at least 12 characters and at most 1,024 bytes.');
    }
    private static function lock(string $data) {
        self::mkdir($data); $lock=fopen($data.'/archive.lock','c+b');
        if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Could not lock backup operations.'); return $lock;
    }
    private static function mkdir(string $path): void { if (!is_dir($path) && !mkdir($path,0700,true) && !is_dir($path)) throw new RuntimeException('Could not create a backup directory.'); }
    private static function pack(string $data,string $stage,string $destination): void {
        $db=Backup::create($stage,'encrypted-snapshot');
        if (filesize($db)>256*1024*1024) throw new InvalidArgumentException('The database exceeds the 256 MiB encrypted-restore limit.');
        $files=['database.db'=>['path'=>$db,'bytes'=>filesize($db),'sha256'=>hash_file('sha256',$db)]];
        self::mkdir($data.'/uploads');
        if (is_link($data.'/uploads')) throw new InvalidArgumentException('Uploads cannot be a symbolic link in a complete encrypted backup.');
        $root=realpath($data.'/uploads');
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        $total=filesize($db);
        foreach ($iterator as $file) {
            if ($file->isLink()) throw new InvalidArgumentException('Uploaded files cannot contain symbolic links in an encrypted backup.');
            if (!$file->isFile()) continue;
            $relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
            if (!self::uploadName($relative)) throw new InvalidArgumentException('Unsupported file in uploads: '.$relative.'. Move it outside uploads before exporting.');
            $info=self::inspectUpload($file->getPathname(),$relative);
            $total+=$info['bytes']; if ($total>self::MAX_EXPANDED || count($files)>=self::MAX_FILES+1) throw new InvalidArgumentException('The backup exceeds 512 MiB expanded or 5,000 uploaded files.');
            $files['uploads/'.$relative]=['path'=>$file->getPathname(),'bytes'=>$info['bytes'],'sha256'=>$info['sha256']];
        }
        self::verifyDocumentReferences($db,$data.'/uploads');
        $zip=new ZipArchive(); if ($zip->open($destination,ZipArchive::CREATE|ZipArchive::EXCL)!==true) throw new RuntimeException('Could not create the backup archive.');
        try {
            $manifest=['version'=>1,'created_at'=>gmdate(DATE_ATOM),'files'=>[]];
            foreach ($files as $name=>$file) {
                if (!$zip->addFile($file['path'],$name)) throw new RuntimeException('Could not add a file to the backup.');
                $manifest['files'][$name]=['bytes'=>$file['bytes'],'sha256'=>$file['sha256']];
            }
            if (!$zip->addFromString('manifest.json',json_encode($manifest,JSON_THROW_ON_ERROR))) throw new RuntimeException('Could not write the backup manifest.');
        } finally { if (!$zip->close()) throw new RuntimeException('Could not finish the backup archive. Check available disk space.'); }
        if (filesize($destination)>self::MAX_ZIP) throw new InvalidArgumentException('The compressed backup exceeds 240 MiB. Export a smaller document collection.');
    }
    private static function uploadName(string $name): bool {
        if(preg_match('~^website-studio/[a-f0-9]{48}\.bin$~D',$name))return true;
        if (strlen($name)>240 || str_contains($name,'\\') || str_contains($name,'..') || str_starts_with($name,'/') || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*/?[A-Za-z0-9_.-]*\.(?:pdf|docx|xlsx|pptx|txt|csv|png|jpg|jpeg|webp)$~D',$name)) return false;
        foreach (explode('/',$name) as $part) if ($part==='' || str_starts_with($part,'.')) return false;
        return true;
    }
    private static function inspectUpload(string $path,string $relative):array {
        return preg_match('~^website-studio/[a-f0-9]{48}\.bin$~D',$relative)?WebAdminService::inspectMedia($path):DocumentService::inspect($path,$relative);
    }
    private static function verifyDocumentReferences(string $database,string $uploads): void {
        $pdo=new PDO('sqlite:'.$database,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='documents'")->fetchColumn()) return;
        foreach ($pdo->query('SELECT storage_name,bytes,sha256 FROM documents') as $row) {
            if (!preg_match('/^[a-f0-9]{32}\.(?:pdf|docx|xlsx|pptx|txt|csv|png|jpg|jpeg|webp)$/D',$row['storage_name'])) throw new InvalidArgumentException('A document has an invalid storage record.');
            $path=$uploads.'/documents/'.$row['storage_name'];
            if (is_link($path) || !is_file($path) || filesize($path)!==(int)$row['bytes'] || !hash_equals($row['sha256'],hash_file('sha256',$path))) throw new InvalidArgumentException('A document file is missing or changed: '.$row['storage_name'].'. Restore its matching document files before continuing.');
        }
        if($pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='website_studio_media'")->fetchColumn())foreach($pdo->query('SELECT storage_name,bytes,sha256 FROM website_studio_media') as $row){
            if(!preg_match('/^[a-f0-9]{48}\.bin$/D',$row['storage_name']))throw new InvalidArgumentException('An Admin Studio media storage record is invalid.');
            $path=$uploads.'/website-studio/'.$row['storage_name'];
            if(is_link($path) || !is_file($path) || filesize($path)!==(int)$row['bytes'] || !hash_equals($row['sha256'],hash_file('sha256',$path)))throw new InvalidArgumentException('An Admin Studio media file is missing or changed. Restore its matching file before continuing.');
        }
    }
    private static function encrypt(string $plain,string $destination,string $passphrase): void {
        $salt=random_bytes(16); $prefix=random_bytes(8); $header=self::MAGIC.$salt.$prefix.pack('N',self::ITERATIONS);
        $key=hash_pbkdf2('sha256',$passphrase,$salt,self::ITERATIONS,32,true);
        $in=fopen($plain,'rb'); $out=fopen($destination,'xb');
        if (!$in || !$out) throw new RuntimeException('Could not open the encrypted backup file.');
        try {
            self::writeAll($out,$header); $index=0;
            while (!feof($in)) {
                $chunk=fread($in,self::CHUNK); if ($chunk===false) throw new RuntimeException('Could not read the backup archive.');
                if ($chunk==='') break;
                self::frame($out,$chunk,$key,$prefix,$header,$index++);
            }
            self::frame($out,'',$key,$prefix,$header,$index); fflush($out); if (function_exists('fsync')) fsync($out);
        } finally { fclose($in); fclose($out); unset($key); }
    }
    private static function frame($out,string $plain,string $key,string $prefix,string $header,int $index): void {
        $length=pack('N',strlen($plain)); $counter=pack('N',$index); $tag='';
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$prefix.$counter,$tag,$header.$counter.$length,16);
        if ($cipher===false || strlen($tag)!==16) throw new RuntimeException('Backup encryption failed.');
        self::writeAll($out,$length.$tag.$cipher);
    }
    private static function decrypt(string $encrypted,string $destination,string $passphrase): void {
        if (!is_file($encrypted) || filesize($encrypted)>self::MAX_ENCRYPTED) throw new InvalidArgumentException('Choose an encrypted LifeOS backup up to 256 MiB.');
        $in=fopen($encrypted,'rb'); $out=fopen($destination,'xb');
        if (!$in || !$out) throw new RuntimeException('Could not open the selected backup.');
        try {
            $magic=self::readExact($in,strlen(self::MAGIC)); if ($magic!==self::MAGIC) throw new InvalidArgumentException('Choose a .lifeosbk encrypted backup created by LifeOS.');
            $salt=self::readExact($in,16); $prefix=self::readExact($in,8); $iterations=self::readExact($in,4);
            if (unpack('N',$iterations)[1]!==self::ITERATIONS) throw new InvalidArgumentException('This encrypted backup version is unsupported.');
            $header=$magic.$salt.$prefix.$iterations; $key=hash_pbkdf2('sha256',$passphrase,$salt,self::ITERATIONS,32,true); $index=0; $total=0;
            while (true) {
                $lengthBytes=self::readExact($in,4); $length=unpack('N',$lengthBytes)[1];
                if ($length>self::CHUNK || ($total+=$length)>self::MAX_ZIP) throw new InvalidArgumentException('This encrypted backup exceeds the supported size.');
                $tag=self::readExact($in,16); $cipher=$length ? self::readExact($in,$length) : ''; $counter=pack('N',$index++);
                $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$prefix.$counter,$tag,$header.$counter.$lengthBytes);
                if ($plain===false) throw new InvalidArgumentException('Could not unlock the backup. Check the passphrase; the file may also be damaged.');
                if ($length===0) { if (fread($in,1)!=='') throw new InvalidArgumentException('Unexpected data after the encrypted backup.'); break; }
                self::writeAll($out,$plain);
            }
        } finally { fclose($in); fclose($out); unset($key); }
    }
    private static function unpack(string $archive,string $destination): void {
        $zip=new ZipArchive(); if ($zip->open($archive)!==true) throw new InvalidArgumentException('The decrypted backup is not a valid archive.');
        self::mkdir($destination); self::mkdir($destination.'/uploads');
        try {
            if ($zip->numFiles<2 || $zip->numFiles>self::MAX_FILES+2) throw new InvalidArgumentException('The backup contains too many files.');
            $raw=$zip->getFromName('manifest.json',4*1024*1024); if ($raw===false) throw new InvalidArgumentException('The backup manifest is missing.');
            $manifest=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
            if (($manifest['version'] ?? null)!==1 || !is_array($manifest['files'] ?? null) || !isset($manifest['files']['database.db'])) throw new InvalidArgumentException('The backup manifest is invalid.');
            $declared=$manifest['files']; $seen=[]; $total=0;
            for ($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i); $name=$stat['name'];
                if (isset($seen[$name])) throw new InvalidArgumentException('The backup has duplicate file entries.'); $seen[$name]=true;
                $zip->getExternalAttributesIndex($i,$opsys,$attributes);
                if ($opsys===3 && (($attributes>>16)&0170000)===0120000) throw new InvalidArgumentException('Symbolic links are not supported in backups.');
                if ($name==='manifest.json') continue;
                if ($name!=='database.db' && (!str_starts_with($name,'uploads/') || !self::uploadName(substr($name,8)))) throw new InvalidArgumentException('The backup contains an unexpected file path.');
                $expected=$declared[$name] ?? null;
                $limit=$name==='database.db' ? 256*1024*1024 : DocumentService::MAX_BYTES;
                if (!is_array($expected) || !is_int($expected['bytes'] ?? null) || $expected['bytes']<1 || $expected['bytes']>$limit || !preg_match('/^[a-f0-9]{64}$/D',$expected['sha256'] ?? '') || $stat['size']!==$expected['bytes']) throw new InvalidArgumentException('The backup file manifest does not match its contents.');
                $total+=$stat['size']; if ($total>self::MAX_EXPANDED || ($stat['comp_size']>0 && $stat['size']/$stat['comp_size']>1000)) throw new InvalidArgumentException('The backup exceeds its safe extraction limits.');
                $target=$destination.'/'.$name; self::mkdir(dirname($target)); $in=$zip->getStream($name); $out=fopen($target,'xb');
                if (!$in || !$out) throw new RuntimeException('Could not stage a restored file.');
                $hash=hash_init('sha256'); $written=0;
                try {
                    while (!feof($in)) { $chunk=fread($in,65536); if ($chunk===false) throw new InvalidArgumentException('A backup entry is damaged.'); $written+=strlen($chunk); if ($written>$expected['bytes']) throw new InvalidArgumentException('A backup entry exceeds its declared size.'); hash_update($hash,$chunk); self::writeAll($out,$chunk); }
                } finally { fclose($in); fclose($out); }
                if ($written!==$expected['bytes'] || !hash_equals($expected['sha256'],hash_final($hash))) throw new InvalidArgumentException('A restored file failed its integrity check.');
                if ($name!=='database.db') self::inspectUpload($target,substr($name,8));
            }
            if (array_diff(array_keys($declared),array_keys($seen))) throw new InvalidArgumentException('The backup is missing declared files.');
        } catch (JsonException $e) { throw new InvalidArgumentException('The backup manifest is invalid.'); }
        finally { $zip->close(); }
    }
    private static function replaceDatabase(string $sourceFile,string $live): void {
        $source=new SQLite3($sourceFile,SQLITE3_OPEN_READONLY); $target=new SQLite3($live); $target->busyTimeout(5000);
        try { if (!$source->backup($target)) throw new RuntimeException('Could not restore the SQLite snapshot.'); $target->exec('PRAGMA wal_checkpoint(TRUNCATE)'); }
        finally { $target->close(); $source->close(); }
    }
    private static function writeJournal(string $file,array $state): void {
        $temporary=$file.'.tmp'; $stream=fopen($temporary,'wb'); if (!$stream) throw new RuntimeException('Could not prepare restore recovery.');
        try { self::writeAll($stream,json_encode($state,JSON_THROW_ON_ERROR)); fflush($stream); if (function_exists('fsync')) fsync($stream); }
        finally { fclose($stream); }
        if (!rename($temporary,$file)) throw new RuntimeException('Could not activate restore recovery.');
    }
    private static function readExact($stream,int $length): string {
        $data=''; while (strlen($data)<$length) { $chunk=fread($stream,$length-strlen($data)); if ($chunk===false || $chunk==='') throw new InvalidArgumentException('The encrypted backup is incomplete or damaged.'); $data.=$chunk; } return $data;
    }
    private static function writeAll($stream,string $data): void {
        $offset=0; while ($offset<strlen($data)) { $written=fwrite($stream,substr($data,$offset)); if ($written===false || $written===0) throw new RuntimeException('Could not write backup data. Check free disk space.'); $offset+=$written; }
    }
    private static function cleanTree(string $path,string $base): void {
        if (!file_exists($path)) return;
        $root=realpath($base); $resolved=realpath($path);
        if (!$root || !$resolved || !str_starts_with(strtolower($resolved),strtolower($root.DIRECTORY_SEPARATOR)) || is_link($path)) throw new RuntimeException('Refusing an unsafe backup cleanup path.');
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { if ($file->isLink() || !$file->isDir()) { if (!unlink($file->getPathname())) throw new RuntimeException('Could not clean staged backup files.'); } elseif (!rmdir($file->getPathname())) throw new RuntimeException('Could not clean a staged backup directory.'); }
        if (!rmdir($path)) throw new RuntimeException('Could not remove a staged backup directory.');
    }
}
