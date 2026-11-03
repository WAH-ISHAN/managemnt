<?php
declare(strict_types=1);
/** Runs explicitly selected local tools without a shell, retaining only reviewed text. */
final class LocalMediaService {
    public static function command(array $arguments,int $timeout=45): string {
        if (!$arguments || !is_file($arguments[0])) throw new InvalidArgumentException(t('Configure the installed local executable first.'));
        $process=proc_open($arguments,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if (!is_resource($process)) throw new InvalidArgumentException(t('Could not start the local tool.'));
        fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $output=''; $error=''; $started=microtime(true); $exit=-1;
        try {
            do {
                $output.=stream_get_contents($pipes[1]); $error.=stream_get_contents($pipes[2]);
                if (strlen($output)+strlen($error)>1000000) throw new InvalidArgumentException(t('Local tool output exceeded the limit.'));
                $status=proc_get_status($process);
                if (!$status['running']) { $exit=$status['exitcode']; break; }
                if (microtime(true)-$started>$timeout) throw new InvalidArgumentException(t('The local tool timed out. Try a shorter note or a smaller model.'));
                usleep(20000);
            } while (true);
            $output.=stream_get_contents($pipes[1]);
        } finally { foreach ([1,2] as $i) fclose($pipes[$i]); if (($status['running'] ?? false)) proc_terminate($process); proc_close($process); }
        if ($exit!==0) throw new InvalidArgumentException(t('The local tool failed. Check its installation, model and supported file format.'));
        return $output;
    }
    public static function upload(array $file,string $kind): string {
        if (!in_array($kind,['receipt','voice'],true) || ($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') || (int)($file['size'] ?? 0)>20*1024*1024) throw new InvalidArgumentException(t('Choose a receipt image or a WAV voice note below 20 MB.'));
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extension=match($mime) {'image/png'=>'png','image/jpeg'=>'jpg','image/tiff'=>'tif','audio/wav','audio/x-wav','audio/vnd.wave'=>'wav',default=>''};
        if ($extension==='' || ($kind==='voice')!==($extension==='wav')) throw new InvalidArgumentException(t('Use PNG/JPEG/TIFF for receipts and WAV for voice notes.'));
        $dir=DATA.'/intelligence-tmp'; if (!is_dir($dir)) mkdir($dir,0700,true);
        $path=$dir.'/'.bin2hex(random_bytes(16)).'.'.$extension;
        if (!move_uploaded_file($file['tmp_name'],$path)) throw new InvalidArgumentException(t('Could not save the temporary input.'));
        return $path;
    }
    public static function convert(string $path,string $kind): string {
        $key=$kind==='receipt' ? 'tesseract' : 'whisper'; $binary=Db::setting('intelligence_'.$key);
        if ($binary==='' || !is_file($binary)) throw new InvalidArgumentException(t('Configure the installed ').$key.' executable first.');
        if ($kind==='receipt') return IntelligenceService::text(self::command([$binary,$path,'stdout','-l',Db::setting('intelligence_ocr_languages','eng'),'--psm','6']),20000);
        if ($kind!=='voice') throw new InvalidArgumentException(t('Invalid media type.'));
        $model=Db::setting('intelligence_whisper_model'); if (!is_file($model)) throw new InvalidArgumentException(t('Configure a local Whisper model first.'));
        $output=$path.'.transcript';
        try {
            self::command([$binary,'-m',$model,'-f',$path,'-otxt','-of',$output,'-l','auto','-t','2','-d','180000'],55);
            if (!is_file($output.'.txt') || filesize($output.'.txt')>100000) throw new InvalidArgumentException(t('No readable transcript was produced.'));
            return IntelligenceService::text(file_get_contents($output.'.txt'),20000);
        } finally { if (is_file($output.'.txt')) unlink($output.'.txt'); }
    }
}
