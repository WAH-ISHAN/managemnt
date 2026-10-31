<?php
return function (Router $r) {
    $r->get('/documents',function () {
        $search=$_GET['q'] ?? ''; if (!is_string($search)) throw new InvalidArgumentException('Enter a document search.'); $search=trim($search); if (mb_strlen($search)>200) throw new InvalidArgumentException('Use a shorter document search.');
        $category=$_GET['category'] ?? ''; if (!is_string($category) || ($category!=='' && !in_array($category,DocumentService::CATEGORIES,true))) throw new InvalidArgumentException('Choose an existing document category.');
        $trash=($_GET['trash'] ?? '')==='1'; $sql='SELECT * FROM documents WHERE deleted_at IS '.($trash ? 'NOT NULL' : 'NULL'); $args=[];
        if ($search!=='') { $sql.=' AND (title LIKE ? ESCAPE \'!\' OR notes LIKE ? ESCAPE \'!\' OR original_name LIKE ? ESCAPE \'!\')'; $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%'; $args=[$term,$term,$term]; }
        if ($category!=='') { $sql.=' AND category=?'; $args[]=$category; }
        $sql.=' ORDER BY created_at DESC,id DESC';
        return View::render(__DIR__.'/views/index.php',['title'=>'Personal documents','rows'=>Db::all($sql,$args),'search'=>$search,'category'=>$category,'trash'=>$trash]);
    });
    $r->post('/documents/upload',function () {
        $file=$_FILES['document'] ?? null;
        if (!$file || $file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Choose a document up to 25 MiB and try again.');
        DocumentService::store($file['tmp_name'],(string)$file['name'],text_input('title'),text_input('category'),text_input('notes',3000,false),DATA);
        return redirect('/documents','Document saved locally.');
    });
    $r->get('/documents/{id}/download',function ($p) {
        $row=Db::one('SELECT * FROM documents WHERE id=?',[record_id($p['id'])]);
        if (!$row) { http_response_code(404); return 'Document not found'; }
        $file=DocumentService::path($row,DATA);
        header('Content-Type: '.$row['mime']); header('Content-Length: '.filesize($file));
        header('Content-Disposition: attachment; filename="document.'.pathinfo($row['storage_name'],PATHINFO_EXTENSION).'"; filename*=UTF-8\'\''.rawurlencode(DocumentService::downloadName($row['original_name'])));
        header('X-Content-Type-Options: nosniff'); readfile($file); return '';
    });
    $r->post('/documents/{id}/update',function ($p) { DocumentService::update(record_id($p['id']),text_input('title'),text_input('category'),text_input('notes',3000,false)); return redirect('/documents','Document details saved.'); });
    $r->post('/documents/{id}/trash',function ($p) { DocumentService::trash(record_id($p['id'])); return redirect('/documents','Document moved to Trash. Its file is kept so you can restore it.'); });
    $r->post('/documents/{id}/restore',function ($p) { DocumentService::trash(record_id($p['id']),false); return redirect('/documents?trash=1','Document restored.'); });
};
