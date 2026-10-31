<?php
return function (Router $r) {
    $r->get('/settings/encrypted',function () {
        if (!class_exists('DocumentService')) throw new InvalidArgumentException('Enable the Documents module to use complete encrypted backups.');
        $files=glob(DATA.'/backups/lifeos-encrypted-*.lifeosbk') ?: []; rsort($files);
        $safety=glob(DATA.'/backups/before-encrypted-restore-*',GLOB_ONLYDIR) ?: []; rsort($safety);
        return View::render(__DIR__.'/../Documents/views/encrypted.php',['title'=>'Encrypted backups','encryptedFiles'=>array_slice($files,0,12),'safetyFolders'=>array_slice($safety,0,5)]);
    });
    $r->post('/settings/encrypted/export',function () {
        $phrase=$_POST['passphrase'] ?? ''; $confirmation=$_POST['passphrase_confirm'] ?? '';
        if (!is_string($phrase) || !is_string($confirmation) || $phrase!==$confirmation) throw new InvalidArgumentException('The backup passphrases must match.');
        $file=EncryptedBackupService::create(DATA,$phrase);
        header('Content-Type: application/octet-stream'); header('Content-Length: '.filesize($file)); header('Content-Disposition: attachment; filename="'.basename($file).'"'); readfile($file); return '';
    });
    $r->get('/settings/encrypted/download/{file}',function ($p) {
        $name=$p['file'];
        if (!preg_match('/^lifeos-encrypted-\d{4}-\d{2}-\d{2}-\d{6}-[a-f0-9]{8}\.lifeosbk$/D',$name) || !is_file(DATA.'/backups/'.$name)) { http_response_code(404); return 'Encrypted backup not found'; }
        $file=DATA.'/backups/'.$name; header('Content-Type: application/octet-stream'); header('Content-Length: '.filesize($file)); header('Content-Disposition: attachment; filename="'.$name.'"'); readfile($file); return '';
    });
    $r->post('/settings/encrypted/restore',function () {
        if (($_POST['confirm'] ?? '')!=='RESTORE') throw new InvalidArgumentException('Type RESTORE to confirm replacing the database and document files.');
        $phrase=$_POST['passphrase'] ?? ''; if (!is_string($phrase)) throw new InvalidArgumentException('Enter the backup passphrase.');
        $upload=$_FILES['backup'] ?? null;
        if (!$upload || $upload['error']!==UPLOAD_ERR_OK || $upload['size']>256*1024*1024 || !is_uploaded_file($upload['tmp_name'])) throw new InvalidArgumentException('Choose a .lifeosbk encrypted backup up to 256 MiB.');
        $mods=Modules::discover(ROOT.'/app/Modules'); $dirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',$mods));
        try { $safety=EncryptedBackupService::restore($upload['tmp_name'],$phrase,DATA,$dirs); }
        catch (PDOException $e) { throw new InvalidArgumentException('The selected backup database is incompatible or damaged. Current data was not replaced.'); }
        $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
        return redirect('/','Encrypted backup restored. Previous database and document files were preserved in data/backups/'.$safety.'.');
    });
};
