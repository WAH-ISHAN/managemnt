<?php
return function (Router $r) {
    $r->get('/knowledge',function () {
        $listing=KnowledgeService::listing($_GET);
        return View::render(__DIR__.'/views/index.php',array_merge($listing,['title'=>'Knowledge Hub','notebooks'=>KnowledgeService::notebooks(),'allTags'=>KnowledgeService::allTags()]));
    });
    $r->get('/knowledge/new',function () {
        $template=$_GET['template']??'blank'; if (!is_string($template) || !isset(KnowledgeService::TEMPLATES[$template])) throw new InvalidArgumentException('Choose a valid note template.');
        return View::render(__DIR__.'/views/edit.php',['title'=>'New note','note'=>null,'template'=>KnowledgeService::TEMPLATES[$template],'notebooks'=>KnowledgeService::notebooks()]);
    });
    $r->get('/knowledge/daily',function () {
        $id=KnowledgeService::daily(); if ($id) return redirect('/knowledge/'.$id);
        return View::render(__DIR__.'/views/edit.php',['title'=>'Today’s note','note'=>null,'template'=>['title'=>date('Y-m-d'),'body'=>"## Today\n\n## Ideas\n\n## Reflection\n",'notebook'=>'Daily'],'notebooks'=>KnowledgeService::notebooks()]);
    });
    $r->post('/knowledge',function () {
        $id=KnowledgeService::save(null,$_POST); return redirect('/knowledge/'.$id,'Note saved on this computer.');
    });
    $r->post('/knowledge/import',function () {
        $file=$_FILES['markdown']??null;
        if (!$file || $file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('Choose a Markdown file up to 50 KB.');
        $id=KnowledgeService::import($file['tmp_name'],(string)$file['name']);
        return redirect('/knowledge/'.$id,'Markdown note imported.');
    });
    $r->get('/knowledge/{id}',function ($p) {
        $note=KnowledgeService::note(record_id($p['id']));
        return View::render(__DIR__.'/views/show.php',['title'=>$note['title'],'note'=>$note,'backlinks'=>KnowledgeService::backlinks($note)]);
    });
    $r->get('/knowledge/{id}/edit',function ($p) {
        $note=KnowledgeService::note(record_id($p['id'])); if ($note['deleted_at']!==null) throw new InvalidArgumentException('Restore this note before editing.');
        return View::render(__DIR__.'/views/edit.php',['title'=>'Edit note','note'=>$note,'template'=>null,'notebooks'=>KnowledgeService::notebooks()]);
    });
    $r->post('/knowledge/{id}/edit',function ($p) {
        $id=KnowledgeService::save(record_id($p['id']),$_POST); return redirect('/knowledge/'.$id,'Note updated.');
    });
    $r->post('/knowledge/{id}/pin',function ($p) {
        $id=record_id($p['id']); KnowledgeService::setFlag($id,'pinned',!((bool)KnowledgeService::note($id)['pinned'])); return redirect('/knowledge/'.$id);
    });
    $r->post('/knowledge/{id}/archive',function ($p) {
        $id=record_id($p['id']); KnowledgeService::setFlag($id,'archived',!((bool)KnowledgeService::note($id)['archived'])); return redirect('/knowledge/'.$id);
    });
    $r->post('/knowledge/{id}/trash',function ($p) { KnowledgeService::trash(record_id($p['id'])); return redirect('/knowledge','Note moved to Trash.'); });
    $r->post('/knowledge/{id}/restore',function ($p) { $id=record_id($p['id']); KnowledgeService::trash($id,true); return redirect('/knowledge/'.$id,'Note restored.'); });
    $r->post('/knowledge/{id}/duplicate',function ($p) { $id=KnowledgeService::duplicate(record_id($p['id'])); return redirect('/knowledge/'.$id,'Copy created.'); });
    $r->get('/knowledge/{id}/export',function ($p) {
        $note=KnowledgeService::note(record_id($p['id'])); if ($note['deleted_at']!==null) throw new InvalidArgumentException('Restore this note before export.');
        $filename='lifeos-note-'.$note['id'].'.md';
        header('Content-Type: text/markdown; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$filename.'"');
        return '# '.$note['title']."\n\n".$note['body']."\n";
    });
};
