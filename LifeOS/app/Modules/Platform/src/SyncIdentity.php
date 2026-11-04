<?php
declare(strict_types=1);
final class SyncIdentity {
 public static function forRow(string $kind,int $id):string{$existing=Db::one('SELECT uuid FROM platform_local_ids WHERE kind=? AND row_id=?',[$kind,$id]);if($existing)return $existing['uuid'];$old=$kind==='tasks'?Db::one('SELECT remote_id uuid FROM platform_sync_map WHERE local_id=? ORDER BY peer LIMIT 1',[$id]):Db::one('SELECT uuid FROM platform_object_maps WHERE kind=? AND local_id=? ORDER BY peer LIMIT 1',[$kind,$id]);$uuid=$old['uuid']??DeviceSyncService::identity()['id'].':'.($kind==='tasks'?'':$kind.':').random_int(1,PHP_INT_MAX);Db::run('INSERT INTO platform_local_ids(kind,row_id,uuid) VALUES(?,?,?)',[$kind,$id,$uuid]);return $uuid;}
 public static function bind(string $kind,int $id,string $uuid):void{$old=Db::one('SELECT uuid FROM platform_local_ids WHERE kind=? AND row_id=?',[$kind,$id]);if(!$old)Db::run('INSERT INTO platform_local_ids(kind,row_id,uuid) VALUES(?,?,?)',[$kind,$id,$uuid]);elseif($old['uuid']!==$uuid)throw new InvalidArgumentException(t('Record identity differs; merge stopped.'));}
}
