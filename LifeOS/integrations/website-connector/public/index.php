<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/content.php';
ini_set('display_errors','0');
header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
$escape=static fn(mixed $text): string=>htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
try {
    $pages=lifeos_published_pages(); $products=lifeos_published_products(); $slug=$_GET['slug'] ?? ''; $selected=null;
    if (!is_string($slug) || strlen($slug)>150) { http_response_code(400); exit('Invalid page.'); }
    if ($slug!=='') { $selected=lifeos_page_by_slug($slug); if (!$selected) http_response_code(404); }
} catch (Throwable) { http_response_code(503); exit('Website content is not configured.'); }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $escape($selected['data']['title'] ?? lifeos_connector_site_name()) ?></title><style>body{font:18px/1.7 system-ui,sans-serif;margin:60px auto;padding:0 24px;max-width:800px;color:#20304a;background:#f5f7fb}a{color:#1767ce}article{padding:28px;background:white;border-radius:20px}h1{line-height:1.2}small{color:#52627b}</style></head><body>
<p><a href="/">Home</a></p>
<?php if ($selected): ?><article><h1><?= $escape($selected['data']['title']) ?></h1><div><?= nl2br($escape($selected['data']['body'])) ?></div></article>
<?php elseif($slug!==''): ?><h1>Page not found</h1>
<?php else: ?><h1><?= $escape(lifeos_connector_site_name()) ?></h1><p>This sample website reads published content from the private connector content store.</p><h2>Pages</h2><?php foreach($pages as $page): ?><p><a href="/?slug=<?= $escape(rawurlencode($page['data']['slug'])) ?>"><?= $escape($page['data']['title']) ?></a></p><?php endforeach; ?><?php if (!$pages): ?><p>No published pages yet.</p><?php endif; ?><h2>Products</h2><?php foreach($products as $product): ?><article><h3><?= $escape($product['data']['name']) ?></h3><p><?= $escape(number_format((float)$product['data']['price'],2)) ?></p><p><?= nl2br($escape($product['data']['description'])) ?></p></article><?php endforeach; ?><?php if (!$products): ?><p>No published products yet.</p><?php endif; ?><?php endif; ?>
<small>Connect your own frontend to this content store before expecting changes on an existing website.</small></body></html>
