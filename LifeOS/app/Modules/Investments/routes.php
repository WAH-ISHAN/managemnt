<?php
return function (Router $r) {
    $r->get('/investments',fn()=>View::render(__DIR__.'/views/index.php',['title'=>'Investments','assets'=>InvestmentService::allWithSummary(),'goals'=>InvestmentService::goals(),'netWorth'=>InvestmentService::netWorth()]));
    $r->post('/investments',function () { $id=InvestmentService::create(text_input('name',100),text_input('type'),text_input('start_date'),text_input('notes',2000,false)); return redirect('/investments/'.$id,'Asset created.'); });
    $r->get('/investments/{id}',function ($p) {
        $id=record_id($p['id']); $a=Db::one('SELECT * FROM assets WHERE id=?',[$id]);
        if (!$a) { http_response_code(404); return View::render(ROOT.'/app/Core/views/error.php',['title'=>'Asset not found','message'=>'Choose an asset from Investments.','back'=>'/investments']); }
        return View::render(__DIR__.'/views/show.php',['title'=>$a['name'],'a'=>$a,'s'=>InvestmentService::summary($id),'series'=>InvestmentService::series($id),'metrics'=>InvestmentService::metrics($id),'accounts'=>Db::all('SELECT * FROM accounts ORDER BY id'),'txs'=>Db::all('SELECT * FROM asset_transactions WHERE asset_id=? ORDER BY date DESC,id DESC',[$id])]);
    });
    $r->post('/investments/{id}/txn',function ($p) { $id=record_id($p['id']); InvestmentService::entry($id,text_input('date'),text_input('type'),$_POST['amount'] ?? '',text_input('note',1000,false),optional_id($_POST['account_id'] ?? '')); return redirect('/investments/'.$id,'Entry saved.'); });
    $r->post('/investments/{id}/txn/{tid}/delete',function ($p) {
        $id=record_id($p['id']); InvestmentService::deleteEntry($id,record_id($p['tid'])); return redirect('/investments/'.$id,'Entry removed and any linked transfer reversed.');
    });
    $r->post('/investments/{id}/metric',function ($p) {
        $id=record_id($p['id']); InvestmentService::asset($id); $month=valid_month(text_input('month'));
        Db::run('INSERT INTO asset_metrics(asset_id,month,revenue,cost) VALUES (?,?,?,?) ON CONFLICT(asset_id,month) DO UPDATE SET revenue=excluded.revenue,cost=excluded.cost',[$id,$month,cents($_POST['revenue'] ?? '',true)/100,cents($_POST['cost'] ?? '',true)/100]); return redirect('/investments/'.$id,'Business month saved.');
    });
    $r->post('/investments/{id}/status',function ($p) { $id=record_id($p['id']); InvestmentService::asset($id); $status=text_input('status'); if (!in_array($status,['active','closed'],true)) throw new InvalidArgumentException('Invalid status.'); Db::run('UPDATE assets SET status=? WHERE id=?',[$status,$id]); return redirect('/investments/'.$id,'Asset status updated.'); });
    $r->post('/goals',function () {
        $asset=optional_id($_POST['asset_id'] ?? ''); if ($asset) InvestmentService::asset($asset);
        Db::run('INSERT INTO goals(title,target_amount,due_date,asset_id) VALUES (?,?,?,?)',[text_input('title'),cents($_POST['target'] ?? '')/100,optional_date($_POST['due'] ?? ''),$asset]); return redirect('/investments','Goal created.');
    });
    $r->post('/goals/{id}/delete',function ($p) { Db::run('DELETE FROM goals WHERE id=?',[record_id($p['id'])]); return redirect('/investments','Goal removed.'); });
};
