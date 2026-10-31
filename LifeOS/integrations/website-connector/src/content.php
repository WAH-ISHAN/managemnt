<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';

/** Example read-only frontend integration. Only published records leave storage. */
function lifeos_published_records(string $collection): array {
    if (!in_array($collection,['pages','products'],true)) throw new InvalidArgumentException('Unknown public collection.');
    $path=lifeos_connector_db_path();
    if (!is_file($path)) return [];
    $db=new SQLite3($path,SQLITE3_OPEN_READONLY); $db->enableExceptions(true); $db->busyTimeout(5000);
    try {
        $statement=$db->prepare('SELECT id,version,data FROM lifeos_records WHERE collection=:collection ORDER BY updated_at DESC,id');
        $statement->bindValue(':collection',$collection,SQLITE3_TEXT); $result=$statement->execute();
        $pages=[];
        while($row=$result->fetchArray(SQLITE3_ASSOC)) {
            $data=json_decode($row['data'],true,32,JSON_THROW_ON_ERROR);
            if (($data['published'] ?? false)===true) $pages[]=['id'=>$row['id'],'version'=>(int)$row['version'],'data'=>$data];
        }
        return $pages;
    } finally { $db->close(); }
}

function lifeos_published_pages(): array { return lifeos_published_records('pages'); }
function lifeos_published_products(): array { return lifeos_published_records('products'); }

function lifeos_page_by_slug(string $slug): ?array {
    foreach(lifeos_published_pages() as $page) if ($page['data']['slug']===$slug) return $page;
    return null;
}
