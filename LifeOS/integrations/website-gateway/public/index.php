<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
    // Terminate TLS here. When behind a proxy configure HTTPS=on in the trusted
    // web-server configuration; untrusted forwarded headers are never accepted.
    if((empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') && !(getenv('LIFEOS_GATEWAY_DEV_LOOPBACK')==='1' && in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true) && preg_match('/^(?:127\.0\.0\.1|localhost)(?::[0-9]+)?$/D',$_SERVER['HTTP_HOST'] ?? ''))) throw new LifeOSConnectorError(403,'HTTPS is required.');
    $path=$_SERVER['PATH_INFO'] ?? '';
    if(!is_string($path)) throw new LifeOSConnectorError(404,'Unknown endpoint.');
    $method=$_SERVER['REQUEST_METHOD'] ?? '';
    if($method==='GET' && $path==='/portal'){header('Content-Type: text/html; charset=utf-8');header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https:; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");require dirname(__DIR__).'/src/portal.php';exit;}
    if($method==='GET' && preg_match('~^/assets/(portal\.js|portal\.css)$~D',$path,$asset)){header('Content-Type: '.(str_ends_with($asset[1],'.js')?'application/javascript':'text/css').'; charset=utf-8');readfile(__DIR__.'/assets/'.$asset[1]);exit;}

    if(str_starts_with($path,'/api/v1/') || str_starts_with($path,'/site/') || str_starts_with($path,'/public/media/')){
        $hub=lifeos_hub();
        if($method==='GET' && preg_match('~^/site/([a-z][a-z0-9_]{0,63})$~D',$path,$match)){$site=$hub->publicSite($match[1]);header('Content-Type: text/html; charset=utf-8');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src https:; base-uri 'none'; frame-ancestors 'none'");require dirname(__DIR__).'/src/public-site.php';exit;}
        if($method==='GET' && preg_match('~^/public/media/([a-f0-9]{32})/([a-f0-9]{64})$~D',$path,$match)){$file=$hub->publicMedia($match[1],$match[2]);header('Content-Type: '.$file['mime']);header('Content-Length: '.$file['bytes']);header('Content-Disposition: '.(str_starts_with($file['mime'],'image/')?'inline':'attachment')."; filename*=UTF-8''".rawurlencode($file['filename']));readfile($file['path']);exit;}
        $header=$_SERVER['HTTP_AUTHORIZATION'] ?? '';$token=str_starts_with($header,'Bearer ')?substr($header,7):null;$input=[];
        if(in_array($method,['POST','PUT'],true) && !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'multipart/form-data')){
            if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'application/json'))throw new LifeOSConnectorError(415,'Use application/json.');
            $raw=file_get_contents('php://input',false,null,0,262145);if($raw===false||strlen($raw)>262144)throw new LifeOSConnectorError(413,'Request too large.');
            try{$input=json_decode($raw,true,48,JSON_THROW_ON_ERROR);}catch(JsonException){throw new LifeOSConnectorError(400,'Invalid JSON.');}
            if(!is_array($input)||($input!==[]&&array_is_list($input)))throw new LifeOSConnectorError(422,'Use a JSON object.');
        }
        $result=$hub->handle($method,$path,$token,$input,$_SERVER['REMOTE_ADDR'] ?? 'unknown',$_FILES);echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);exit;
    }
    $gateway=lifeos_gateway();
    if($method==='GET' && preg_match('~^/content/([a-z][a-z0-9_]{0,63})$~D',$path,$match)) $result=$gateway->publicContent($match[1]);
    else {
        if($method!=='POST') throw new LifeOSConnectorError(405,'Use POST for account and admin actions.');
        $slug='';if($path!=='' && $path!=='/' && !preg_match('~^/sites/([a-z][a-z0-9_]{0,63})$~D',$path,$match)) throw new LifeOSConnectorError(404,'Unknown endpoint.');
        if($path!=='' && $path!=='/')$slug=$match[1];
        if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'application/json'))throw new LifeOSConnectorError(415,'Use application/json.');
        $body=file_get_contents('php://input',false,null,0,262145);
        if($body===false || strlen($body)>262144)throw new LifeOSConnectorError(413,'Request too large.');
        try{$request=json_decode($body,true,24,JSON_THROW_ON_ERROR);}catch(JsonException){throw new LifeOSConnectorError(400,'Invalid JSON.');}
        if(!is_array($request) || array_is_list($request)) throw new LifeOSConnectorError(422,'Invalid request.');
        $header=$_SERVER['HTTP_AUTHORIZATION'] ?? '';$token=str_starts_with($header,'Bearer ')?substr($header,7):null;
        $result=$gateway->dispatch($slug,$token,$request,$_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}catch(Throwable $error){http_response_code($error instanceof LifeOSConnectorError?$error->status:503);echo json_encode(['protocol'=>'lifeos-admin-v1','error'=>$error instanceof LifeOSConnectorError?$error->getMessage():'Gateway unavailable.']);}
