<?php
declare(strict_types=1);
$root=dirname(__DIR__);$pairs=[];
foreach(glob($root.'/app/Modules/Onboarding/locales/*.php') as $file)$pairs=array_replace($pairs,require $file);
foreach(['si','ta']as $index=>$language){$catalog=[];foreach($pairs as $key=>$pair)$catalog[$key]=$pair[$index];file_put_contents($root.'/public/assets/locale-'.$language.'.js','window.lifeosLocale='.json_encode($catalog,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).';');}
echo count($pairs)." locale pairs exported for browser UI.\n";
