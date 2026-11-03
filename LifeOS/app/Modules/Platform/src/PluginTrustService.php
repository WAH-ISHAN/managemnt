<?php
declare(strict_types=1);
final class PluginTrustService {
    public const MODULE_API=1;
    public const DEVELOPMENT_VERSION='1.9.0';
    public static function compatibility(array $manifest):void{
        $c=$manifest['compatibility']??null;if(!is_array($c)||($c['module_api']??null)!==self::MODULE_API)throw new InvalidArgumentException(t('Plugin must declare supported module_api 1.'));$version=self::DEVELOPMENT_VERSION;if(is_file(ROOT.'/lifeos-release.json')){$release=json_decode(file_get_contents(ROOT.'/lifeos-release.json'),true);if(is_string($release['version']??null))$version=ltrim($release['version'],'v');}
        foreach(['lifeos_min','lifeos_max_exclusive','php_min']as $key)if(!is_string($c[$key]??null)||!preg_match('/^\d+\.\d+\.\d+$/D',$c[$key]))throw new InvalidArgumentException(t('Declare LifeOS/PHP compatibility version bounds.'));if(version_compare($version,$c['lifeos_min'],'<')||version_compare($version,$c['lifeos_max_exclusive'],'>=')||version_compare(PHP_VERSION,$c['php_min'],'<'))throw new InvalidArgumentException(t('Plugin version bounds do not support this LifeOS/PHP runtime.'));$requires=$manifest['requires']??[];if(!is_array($requires))throw new InvalidArgumentException(t('Invalid module dependencies.'));$mods=[];foreach(Modules::discover(ROOT.'/app/Modules')as $m)$mods[basename($m['path'])]=$m;foreach($requires as $id=>$min){if(!is_string($id)||!is_string($min)||!isset($mods[$id])||version_compare($mods[$id]['version']??'0.0.0',$min,'<'))throw new InvalidArgumentException(t('A required module is missing or too old.'));}
    }
    public static function trust(string $name,string $public):void{$key=base64_decode($public,true);if(!$key||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||trim($name)===''||strlen($name)>100)throw new InvalidArgumentException(t('Enter a publisher name and an Ed25519 public key.'));Db::run('INSERT INTO platform_publishers(public_key,name) VALUES(?,?) ON CONFLICT(public_key) DO UPDATE SET name=excluded.name',[base64_encode($key),trim($name)]);PlatformService::audit('publisher-trusted',hash('sha256',$key));}
    public static function inspect(string $path,string $public,string $signature):array{
        if(!is_file($path)||filesize($path)>10485760)throw new InvalidArgumentException(t('Choose a ZIP up to 10 MiB.'));$key=base64_decode($public,true);$sig=base64_decode($signature,true);$known=Db::one('SELECT name FROM platform_publishers WHERE public_key=?',[$public]);if(!$known||!$key||strlen($key)!==32||!$sig||strlen($sig)!==64||!sodium_crypto_sign_verify_detached($sig,file_get_contents($path),$key))throw new InvalidArgumentException(t('Publisher is not trusted or ZIP signature does not match.'));
        $zip=new ZipArchive();if($zip->open($path)!==true)throw new InvalidArgumentException(t('Invalid ZIP.'));try{$files=[];$total=0;if($zip->numFiles>200)throw new InvalidArgumentException(t('Plugin has too many files.'));for($i=0;$i<$zip->numFiles;$i++){$stat=$zip->statIndex($i);$name=$stat['name'];$zip->getExternalAttributesIndex($i,$os,$attr);if(($attr>>16&0170000)===0120000||str_contains($name,'\\')||str_contains($name,':')||str_starts_with($name,'/')||preg_match('#(^|/)\.\.?(/|$)#',$name)||!preg_match('#^(module\.json|routes\.php|(?:src|views|migrations|resources|assets)/[A-Za-z0-9_./-]+)$#D',$name))throw new InvalidArgumentException(t('Unsafe plugin path.'));if(str_ends_with($name,'/'))continue;$total+=$stat['size'];if($stat['size']>2097152||$total>10485760)throw new InvalidArgumentException(t('Unpacked plugin exceeds limits.'));$files[$name]=$zip->getFromIndex($i);}
        $manifest=json_decode($files['module.json']??'',true,32,JSON_THROW_ON_ERROR);self::compatibility($manifest);$id=$manifest['id']??'';if(!is_string($id)||!preg_match('/^[A-Z][A-Za-z0-9]{2,39}$/D',$id)||(is_dir(ROOT.'/app/Modules/'.$id)||is_dir(ROOT.'/app/Modules/'.$id.'.disabled'))||!preg_match('#^/[a-z][a-z0-9/-]*$#D',$manifest['route']??'')||!is_string($manifest['name']??null)||trim($manifest['name'])==='')throw new InvalidArgumentException(t('Plugin needs a new module ID, name and valid route.'));foreach(Modules::discover(ROOT.'/app/Modules')as $existing)if($existing['route']===$manifest['route'])throw new InvalidArgumentException(t('Plugin route already exists.'));return ['id'=>$id,'manifest'=>$manifest,'files'=>$files,'sha256'=>hash_file('sha256',$path),'publisher'=>$public];}finally{$zip->close();}
    }
    public static function install(string $path,string $public,string $signature,string $expected):void{
        $p=self::inspect($path,$public,$signature);
        if(!hash_equals($p['sha256'],$expected))throw new InvalidArgumentException(t('Package changed since preview.'));
        $stage=ROOT.'/app/Modules/.plugin-'.bin2hex(random_bytes(10));$active=ROOT.'/app/Modules/'.$p['id'];$activated=false;
        if(!mkdir($stage,0700))throw new RuntimeException(t('Plugin staging failed.'));
        try{
            foreach($p['files']as $name=>$content){
                $target=$stage.'/'.$name;if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);
                if(file_put_contents($target,$content)===false)throw new RuntimeException(t('Plugin staging failed.'));
                if(str_ends_with($name,'.php')){
                    $proc=proc_open([PHP_BINARY,'-l',$target],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
                    if(!is_resource($proc))throw new RuntimeException(t('Could not validate plugin PHP syntax.'));
                    stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
                    if(proc_close($proc)!==0)throw new InvalidArgumentException(t('Plugin contains invalid PHP syntax.'));
                }
            }
            if(defined('DATA')&&class_exists('Backup'))Backup::create(DATA.'/backups','before-plugin');
            Db::tx(function()use($p,$public,$stage,$active,&$activated){
                $files=$p['files'];ksort($files);
                foreach($files as $name=>$sql)if(preg_match('#^migrations/[0-9][A-Za-z0-9_-]*\\.sql$#D',$name)){
                    $key=$p['id'].'/'.basename($name);$sql=str_replace("\r\n","\n",$sql);$hash=hash('sha256',$sql);
                    $old=Db::one('SELECT checksum FROM migrations WHERE file=?',[$key]);
                    if($old&&$old['checksum']!==$hash)throw new InvalidArgumentException(t('Plugin changes an applied migration.'));
                    if(!$old){Db::exec($sql);Db::run('INSERT INTO migrations(file,checksum) VALUES(?,?)',[$key,$hash]);}
                }
                Db::run('INSERT INTO platform_plugins(name,sha256,publisher) VALUES(?,?,?)',[$p['id'],$p['sha256'],$public]);
                PlatformService::audit('plugin-installed',$p['id'].' '.$p['sha256']);
                if(!rename($stage,$active))throw new RuntimeException(t('Could not activate plugin.'));
                $activated=true;
            });
        }catch(Throwable $e){
            if($activated&&is_dir($active))rename($active,$stage);
            if(is_dir($stage)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($stage);}
            throw $e;
        }
    }
}
