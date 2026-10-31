<?php
declare(strict_types=1);
require_once __DIR__.'/Gateway.php';
function lifeos_gateway(): LifeOSGateway {
    $path=getenv('LIFEOS_GATEWAY_DB') ?: '';$key=base64_decode(getenv('LIFEOS_GATEWAY_KEY') ?: '',true);
    if(!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~D',$path) || !is_dir(dirname($path))) throw new RuntimeException('Configure an absolute private gateway database path.');
    $public=realpath(__DIR__.'/../public');$parent=realpath(dirname($path));
    $normalized=strtolower(str_replace('\\','/',$parent));$root=strtolower(str_replace('\\','/',$public));
    $roots=[$root];$actual=realpath($_SERVER['DOCUMENT_ROOT'] ?? '');if(is_string($actual) && $actual!=='')$roots[]=strtolower(str_replace('\\','/',$actual));
    foreach($roots as $root)if($normalized===$root || str_starts_with($normalized,$root.'/') || is_link($path)) throw new RuntimeException('Gateway storage must be outside the public directory.');
    return new LifeOSGateway($path,is_string($key)?$key:'',getenv('LIFEOS_GATEWAY_URL') ?: '');
}
function lifeos_hub(): LifeOSHub {
    require_once __DIR__.'/Hub.php';
    // Reuse the same private-path validation and stable encryption key.
    lifeos_gateway();
    $key=base64_decode(getenv('LIFEOS_GATEWAY_KEY') ?: '',true);
    return new LifeOSHub(getenv('LIFEOS_GATEWAY_DB') ?: '',is_string($key)?$key:'',getenv('LIFEOS_GATEWAY_URL') ?: '',getenv('LIFEOS_GATEWAY_OPERATOR_TOKEN') ?: '');
}
