<?php
declare(strict_types=1);
final class DocumentService {
    public const MAX_BYTES=25*1024*1024;
    public const CATEGORIES=['Certificates','CV & career','University','Personal','Other'];
    public const EXTENSIONS=['pdf','docx','xlsx','pptx','txt','csv','png','jpg','jpeg','webp'];
    public static function inspect(string $file,string $name): array {
        $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if (!in_array($extension,self::EXTENSIONS,true)) throw new InvalidArgumentException('Choose PDF, DOCX, XLSX, PPTX, TXT, CSV, PNG, JPEG or WebP.');
        $bytes=filesize($file); if (!$bytes || $bytes>self::MAX_BYTES) throw new InvalidArgumentException('Choose a non-empty document up to 25 MiB.');
        $head=file_get_contents($file,false,null,0,16); $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);
        $valid=match($extension) {
            'pdf'=>str_starts_with($head,'%PDF-'),
            'png'=>str_starts_with($head,"\x89PNG\r\n\x1a\n"),
            'jpg','jpeg'=>str_starts_with($head,"\xff\xd8\xff"),
            'webp'=>str_starts_with($head,'RIFF') && substr($head,8,4)==='WEBP',
            'docx','xlsx','pptx'=>self::office($file,$extension),
            'txt','csv'=>self::text($file),
            default=>false,
        };
        if (!$valid) throw new InvalidArgumentException('The document content does not match its file extension.');
        $mime=match($extension) { 'pdf'=>'application/pdf','png'=>'image/png','jpg','jpeg'=>'image/jpeg','webp'=>'image/webp','txt'=>'text/plain','csv'=>'text/csv','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation' };
        return ['extension'=>$extension,'mime'=>$mime,'bytes'=>$bytes,'sha256'=>hash_file('sha256',$file)];
    }
    private static function text(string $file): bool {
        $stream=fopen($file,'rb');
        try { while (!feof($stream)) if (str_contains(fread($stream,65536),"\0")) return false; return true; }
        finally { fclose($stream); }
    }
    private static function office(string $file,string $extension): bool {
        if (!class_exists('ZipArchive')) throw new RuntimeException('The PHP zip extension is required for Office documents.');
        $zip=new ZipArchive(); if ($zip->open($file)!==true) return false;
        try {
            $required=match($extension) { 'docx'=>'word/document.xml','xlsx'=>'xl/workbook.xml','pptx'=>'ppt/presentation.xml' };
            if ($zip->numFiles>2000 || $zip->locateName('[Content_Types].xml')===false || $zip->locateName($required)===false) return false;
            $total=0;
            for ($i=0;$i<$zip->numFiles;$i++) {
                $s=$zip->statIndex($i); $name=$s['name']; $total+=$s['size'];
                if ($total>100*1024*1024 || str_contains($name,'..') || str_contains($name,'\\') || str_starts_with($name,'/') || preg_match('/(?:vbaProject\.bin|\.(?:exe|dll|js|vbs|cmd|bat|ps1))$/i',$name)) return false;
            }
            return true;
        } finally { $zip->close(); }
    }
    public static function store(string $temporary,string $original,string $title,string $category,string $notes,string $data): int {
        $title=trim($title); $notes=trim($notes);
        if ($title==='' || mb_strlen($title)>200 || mb_strlen($notes)>3000 || !in_array($category,self::CATEGORIES,true)) throw new InvalidArgumentException('Enter a title, an existing category and notes under 3,000 characters.');
        if (!is_file($temporary) || is_link($temporary)) throw new InvalidArgumentException('The uploaded file is unavailable.');
        $info=self::inspect($temporary,$original); $directory=$data.'/uploads/documents';
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Could not create document storage.');
        $name=bin2hex(random_bytes(16)).'.'.$info['extension']; $destination=$directory.'/'.$name;
        $in=fopen($temporary,'rb'); $out=fopen($destination,'xb');
        if (!$in || !$out) { if (is_resource($in)) fclose($in); if (is_resource($out)) fclose($out); if (is_file($destination)) @unlink($destination); throw new RuntimeException('Could not open local document storage.'); }
        try { if (stream_copy_to_stream($in,$out)!==$info['bytes']) throw new RuntimeException('The document could not be saved completely.'); }
        catch (Throwable $e) { fclose($in); fclose($out); @unlink($destination); throw $e; }
        fclose($in); fclose($out);
        try { return Db::run('INSERT INTO documents(title,category,notes,original_name,storage_name,mime,bytes,sha256) VALUES (?,?,?,?,?,?,?,?)',[$title,$category,$notes,self::downloadName($original),$name,$info['mime'],$info['bytes'],$info['sha256']]); }
        catch (Throwable $e) { @unlink($destination); throw $e; }
    }
    public static function path(array $row,string $data): string {
        if (!preg_match('/^[a-f0-9]{32}\.(?:pdf|docx|xlsx|pptx|txt|csv|png|jpg|jpeg|webp)$/D',$row['storage_name'] ?? '')) throw new RuntimeException('Invalid document storage record.');
        $path=$data.'/uploads/documents/'.$row['storage_name'];
        if (is_link($path) || !is_file($path)) throw new InvalidArgumentException('The document file is missing. Restore a backup containing its files.');
        return $path;
    }
    public static function downloadName(string $name): string {
        $name=basename(str_replace('\\','/',$name)); $name=preg_replace('/[\x00-\x1f\x7f"<>:|?*]/u','_',$name) ?? 'document';
        return mb_substr($name,0,200) ?: 'document';
    }
    public static function update(int $id,string $title,string $category,string $notes): void {
        if (trim($title)==='' || mb_strlen($title)>200 || mb_strlen($notes)>3000 || !in_array($category,self::CATEGORIES,true)) throw new InvalidArgumentException('Enter valid document details.');
        if (!Db::one('SELECT id FROM documents WHERE id=?',[$id])) throw new InvalidArgumentException('Document not found.');
        Db::run('UPDATE documents SET title=?,category=?,notes=? WHERE id=?',[trim($title),$category,trim($notes),$id]);
    }
    public static function trash(int $id,bool $deleted=true): void {
        if (!Db::one('SELECT id FROM documents WHERE id=?',[$id])) throw new InvalidArgumentException('Document not found.');
        Db::run('UPDATE documents SET deleted_at=? WHERE id=?',[$deleted ? gmdate('Y-m-d H:i:s') : null,$id]);
    }
}
