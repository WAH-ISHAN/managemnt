<?php
declare(strict_types=1);

/** Resolve the explicitly configured private content store; never invent a path. */
function lifeos_connector_db_path(): string {
    $configured=getenv('LIFEOS_CONNECTOR_DB');
    if (!is_string($configured) || $configured==='' || str_contains($configured,"\0") || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~D',$configured)) {
        throw new RuntimeException('Connector storage is not configured.');
    }
    $parent=realpath(dirname($configured));
    if ($parent===false || !is_dir($parent)) throw new RuntimeException('Connector storage is not configured.');
    $path=is_file($configured) ? realpath($configured) : $parent.DIRECTORY_SEPARATOR.basename($configured);
    if (!is_string($path)) throw new RuntimeException('Connector storage is not configured.');
    $documentRoot=realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__).'/public'));
    if ($documentRoot===false) throw new RuntimeException('Connector document root is not configured.');
    $normalize=static fn(string $value): string=>rtrim(str_replace('\\','/',$value),'/');
    $resolved=$normalize($path); $public=$normalize($documentRoot);
    if (PHP_OS_FAMILY==='Windows') { $resolved=strtolower($resolved); $public=strtolower($public); }
    if ($resolved===$public || str_starts_with($resolved,$public.'/')) throw new RuntimeException('Connector storage must be outside the website document root.');
    return $path;
}

function lifeos_connector_site_name(): string {
    $name=getenv('LIFEOS_CONNECTOR_SITE_NAME');
    return is_string($name) && trim($name)!=='' && mb_strlen($name)<=200 && !str_contains($name,"\0") ? trim($name) : 'My website';
}
