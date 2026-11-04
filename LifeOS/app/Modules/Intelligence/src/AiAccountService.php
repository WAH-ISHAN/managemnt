<?php
declare(strict_types=1);
final class AiAccountService {
 public static function connected():bool{
  $file=OpenCodeCliProvider::directory().'/account.json';if(!is_file($file)||filesize($file)>10000)return false;
  $row=json_decode(file_get_contents($file),true,8);return is_array($row)&&($row['connected']??false)===true&&($row['owner']??'')===self::owner();
 }
 public static function owner():string{return class_exists('LicenseService')?(string)(LicenseService::state()['user']['id']??''):'';}
 public static function required():bool{return class_exists('LicenseService')&&(bool)LicenseService::config()['required'];}
 public static function state():array{
  $dir=OpenCodeCliProvider::directory();$cached=[];$path=$dir.'/account.json';
  if(is_file($path)&&filesize($path)<10000){$value=json_decode(file_get_contents($path),true,8);if(is_array($value))$cached=$value;}
  $jobs=[];
  foreach(glob($dir.'/jobs/*.json')?:[]as $file){if(filesize($file)>200000)continue;$row=json_decode(file_get_contents($file),true,16);if(!is_array($row)||!str_starts_with($row['kind']??'','account_'))continue;$jobs[]=$row;}
  usort($jobs,fn($a,$b)=>($b['created_at']??0)<=>($a['created_at']??0));$job=null;
  if($jobs){$raw=(new OpenCodeCliProvider())->result($jobs[0]['id']);$job=['id'=>$raw['id'],'kind'=>$raw['kind'],'status'=>$raw['status'],'error'=>$raw['status']==='failed'?'AI account connection could not finish. Try again.':''];
   if($raw['status']==='running'&&isset($raw['authorization'])&&($raw['authorization']['expires_at']??0)>time()){
    $authorization=$raw['authorization'];$url=$authorization['url']??'';$parts=is_string($url)?parse_url($url):false;
    if(is_array($parts)&&($parts['scheme']??'')==='https'&&($parts['host']??'')==='opencode.ai'&&!isset($parts['user'])&&!isset($parts['pass'])&&(!isset($parts['port'])||$parts['port']===443))$job['authorization']=['url'=>$url,'instructions'=>mb_substr((string)($authorization['instructions']??''),0,1000),'expires_at'=>(int)$authorization['expires_at']];
   }
  }
  return ['connected'=>self::connected(),'checkedAt'=>(int)($cached['updated_at']??0),'job'=>$job];
 }
 public static function cancel(string $id):void{
  $job=(new OpenCodeCliProvider())->result($id);
  if(($job['kind']??'')!=='account_connect'||!in_array($job['status'],['queued','running'],true))throw new InvalidArgumentException('No sign-in is waiting to be cancelled.');
  file_put_contents(OpenCodeCliProvider::directory().'/jobs/'.$id.'.cancel','cancel',LOCK_EX);
 }
}
