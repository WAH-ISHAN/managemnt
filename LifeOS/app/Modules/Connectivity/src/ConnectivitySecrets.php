<?php
declare(strict_types=1);
/** Machine-local key is intentionally excluded from database/document backups. */
final class ConnectivitySecrets {
    private static function key(): string {
        $file=DATA.'/.connectivity-key';
        if (!is_file($file)) {
            $handle=@fopen($file,'x+b');
            if ($handle) { chmod($file,0600); fwrite($handle,random_bytes(32)); fclose($handle); }
        }
        $key=is_file($file) ? file_get_contents($file) : false;
        if (!is_string($key) || strlen($key)!==32) throw new RuntimeException(t('The connection secret key is unavailable. Reconfigure connection credentials on this device.'));
        return $key;
    }
    public static function save(string $name,string $value): void {
        if ($value==='') return;
        if (strlen($value)>8192 || str_contains($value,"\0")) throw new InvalidArgumentException(t('Connection secret is too long.'));
        $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($value,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag,$name);
        if ($cipher===false) throw new RuntimeException(t('Could not protect the connection secret.'));
        Db::set('connection_secret_'.$name,base64_encode($iv.$tag.$cipher));
    }
    public static function get(string $name): string {
        $raw=base64_decode(Db::setting('connection_secret_'.$name),true);
        if ($raw===false || $raw==='') return '';
        if (strlen($raw)<29) throw new RuntimeException(t('Invalid connection secret. Re-enter it.'));
        $value=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$name);
        if ($value===false) throw new RuntimeException(t('Connection credentials belong to another device. Re-enter them.'));
        return $value;
    }
    public static function forget(string $name): void { Db::run('DELETE FROM settings WHERE key=?',['connection_secret_'.$name]); }
}
