<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
try {
    $gateway=lifeos_gateway();$command=$argv[1] ?? '';
    if($command==='site'){$site=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$gateway->provision($site);echo "Website provisioned.\n";}
    elseif($command==='account'){$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);$password=$gateway->accounts->provisionSites($input['username'] ?? '',$input['sites'] ?? []);echo json_encode(['username'=>$input['username'],'password'=>$password],JSON_THROW_ON_ERROR)."\n";}
    elseif($command==='disable'){$gateway->accounts->disable($argv[2] ?? '');echo "Account disabled; sessions revoked.\n";}
    else throw new InvalidArgumentException('Usage: php bin/manage.php site|account (JSON on stdin), or disable USERNAME. Reprovisioning an account rotates its password and revokes all sessions.');
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
