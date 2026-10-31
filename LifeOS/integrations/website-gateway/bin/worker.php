<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
try{$hub=lifeos_hub();$count=$hub->work(10);echo $count." publish deliveries processed.\n";}catch(Throwable $e){fwrite(STDERR,"Hub worker failed. Check server configuration and private logs.\n");exit(1);}
