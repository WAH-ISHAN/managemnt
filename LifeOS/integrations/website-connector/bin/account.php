<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/Connector.php';
try {
    $command=$argv[1] ?? ''; $username=$argv[2] ?? '';
    if (!in_array($command,['set','disable'],true) || $username==='') throw new InvalidArgumentException('Usage: php bin/account.php set USERNAME viewer|editor|admin [pages,products] OR disable USERNAME');
    $accounts=new LifeOSConnectorAccounts(lifeos_connector_db_path());
    if ($command==='disable') { $accounts->disable($username); echo "Account disabled and sessions revoked.\n"; }
    else {
        $password=$accounts->provision($username,$argv[3] ?? '',explode(',',$argv[4] ?? 'pages,products'));
        echo "Account ready. Existing sessions revoked.\nUsername: ".$username."\nPassword: ".$password."\nDeliver this generated password privately to the client.\n";
    }
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
