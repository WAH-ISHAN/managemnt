<?php
declare(strict_types=1);
return function (Router $r): void {
    $r->get('/assistant',fn()=>View::render(__DIR__.'/views/panel.php',['title'=>'LifeOS AI'],'assistant'));
    $r->get('/assistant/state',function(){header('Content-Type: application/json; charset=utf-8');return json_encode((new OpenCodeCliProvider())->panelState(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);});
    $r->post('/assistant/message',function(){(new OpenCodeCliProvider())->submitAutomatic(text_input('message',2000));header('Content-Type: application/json; charset=utf-8');return json_encode((new OpenCodeCliProvider())->panelState(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);});
    $r->post('/assistant/new',function(){OpenCodeCliProvider::thread(true);header('Content-Type: application/json; charset=utf-8');return json_encode((new OpenCodeCliProvider())->panelState(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);});
    $r->post('/assistant/action/{id}',function($p){(new OpenCodeCliProvider())->apply($p['id'],int_range($_POST['index']??'',0,4),$_POST);header('Content-Type: application/json; charset=utf-8');return json_encode((new OpenCodeCliProvider())->panelState(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);});
    $page=static function (array $extra=[]): string {
        View::share('aiJob',isset($_GET['job'])?(new OpenCodeCliProvider())->result(is_string($_GET['job'])?$_GET['job']:''):null);
        View::share('aiModels',(new OpenCodeCliProvider())->models());
        $draft=isset($_GET['draft']) ? IntelligenceService::draft(record_id($_GET['draft'])) : null;
        $source=isset($_GET['source']) ? IntelligenceService::sourceRow(record_id($_GET['source'])) : null;
        return View::render(__DIR__.'/views/index.php',array_merge(['title'=>t('Local assistant'),'draft'=>$draft,'source'=>$source,'answer'=>null,'sources'=>Db::all('SELECT * FROM intelligence_sources ORDER BY id DESC LIMIT 20'),'drafts'=>Db::all('SELECT * FROM intelligence_drafts ORDER BY id DESC LIMIT 20')],$extra));
    };
    $r->get('/intelligence',$page);
    $r->post('/intelligence/models',function(){return redirect('/intelligence?job='.(new OpenCodeCliProvider())->refresh());});
    $r->post('/intelligence/chat',function(){
        if(!isset($_POST['send_external']))throw new InvalidArgumentException(t('Confirm that your typed question can be sent to the online AI service.'));
        $id=(new OpenCodeCliProvider())->submit(text_input('question',2000),text_input('model',150),OpenCodeCliProvider::context(isset($_POST['include_totals'])));
        return redirect('/intelligence?job='.$id);
    });
    $r->get('/intelligence/chat/{id}',function($p){$job=(new OpenCodeCliProvider())->result($p['id']);header('Content-Type: application/json');return json_encode(['id'=>$job['id'],'status'=>$job['status'],'error'=>$job['error']??null,'kind'=>$job['kind']],JSON_THROW_ON_ERROR);});
    $r->post('/intelligence/chat/{id}/apply',function($p){(new OpenCodeCliProvider())->apply($p['id'],int_range($_POST['index']??'',0,4),$_POST);return redirect('/intelligence?job='.$p['id'],t('Reviewed assistant action applied once.'));});
    $r->post('/intelligence/settings',function (): string { IntelligenceService::configure($_POST); return redirect('/intelligence','Local tool configuration saved.'); });
    $r->post('/intelligence/ask',function () use ($page): string { return $page(['answer'=>IntelligenceService::ask(text_input('question',2000))]); });
    $r->post('/intelligence/note',function (): string { $source=IntelligenceService::source('note',text_input('body',20000)); return redirect('/intelligence?source='.$source,'Source saved. Review its action items.'); });
    $r->post('/intelligence/media',function (): string {
        $kind=text_input('kind',20); $path=LocalMediaService::upload($_FILES['media'] ?? [],$kind);
        try { $body=LocalMediaService::convert($path,$kind); $source=IntelligenceService::source($kind,$body); }
        finally { if (is_file($path)) unlink($path); }
        return redirect('/intelligence?source='.$source,'Local text is ready. Correct it before creating a preview.');
    });
    $r->post('/intelligence/source/{id}/preview',function (array $p): string {
        $id=record_id($p['id']); $source=IntelligenceService::sourceRow($id); $body=IntelligenceService::text(text_input('body',20000));
        Db::run('UPDATE intelligence_sources SET body=? WHERE id=?',[$body,$id]);
        $draft=$source['kind']==='receipt' ? IntelligenceService::receiptDraft($id) : IntelligenceService::draftTasks($id);
        return redirect('/intelligence?draft='.$draft,'Review every proposed change before applying.');
    });
    $r->post('/intelligence/move',function (): string { $id=IntelligenceService::movePreview(text_input('command',500)); return redirect('/intelligence?draft='.$id,'Task move preview is ready.'); });
    $r->post('/intelligence/draft/{id}/apply',function (array $p): string { IntelligenceService::apply(record_id($p['id']),$_POST); return redirect('/intelligence','Reviewed action applied.'); });
    $r->post('/intelligence/draft/{id}/undo',function (array $p): string { IntelligenceService::undo(record_id($p['id'])); return redirect('/intelligence','Task move undone.'); });
    $r->post('/intelligence/source/{id}/delete',function (array $p): string { $id=record_id($p['id']); IntelligenceService::sourceRow($id); Db::run('DELETE FROM intelligence_sources WHERE id=?',[$id]); return redirect('/intelligence','Source and previews deleted. Created tasks remain in Planner.'); });
};
