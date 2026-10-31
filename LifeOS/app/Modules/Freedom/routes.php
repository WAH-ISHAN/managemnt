<?php
return function (Router $r) {
    $r->get('/freedom',function () {
        $edit=optional_id($_GET['edit'] ?? '');
        return View::render(__DIR__.'/views/index.php',['title'=>'Your freedom','freedom'=>FreedomService::overview(),'categories'=>FreedomService::CATEGORIES,'editTime'=>$edit ? FreedomService::record($edit) : null]);
    });
    $r->post('/freedom/settings',function () { FreedomService::saveSettings($_POST); return redirect('/freedom','Your work hours were saved.'); });
    $r->post('/freedom/time',function () { FreedomService::saveTime(optional_id($_POST['id'] ?? ''),$_POST); return redirect('/freedom#personal-time','Your personal time entry was saved.'); });
    $r->post('/freedom/time/{id}/update',function ($p) { FreedomService::saveTime(record_id($p['id']),$_POST); return redirect('/freedom#personal-time','Your personal time entry was updated.'); });
    $r->post('/freedom/time/{id}/delete',function ($p) { FreedomService::deleteTime(record_id($p['id'])); return redirect('/freedom#personal-time','Your personal time entry was removed.'); });
};
