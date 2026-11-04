<?php
return function(Router $r){
 $r->post('/employee/claims/{id}/expense',function($p){EmployeeMoney::expense(record_id($p['id']),record_id($_POST['account_id']??''),record_id($_POST['category_id']??''));return redirect('/employee?page=claims','Claim expense posted once to Money.');});
 $r->post('/employee/{kind}/{id}/receipt',function($p){EmployeeMoney::receipt($p['kind'],record_id($p['id']),text_input('date',10));return redirect('/employee?page='.urlencode($p['kind']),'Receipt posted through your Money allocation rules.');});
 $r->get('/employee',function(){$kind=$_GET['page']??'attendance';if(!is_string($kind))throw new InvalidArgumentException(t('Choose an employee page.'));EmployeeService::definition($kind);$search=is_string($_GET['q']??'')?mb_substr($_GET['q']??'',0,200):'';return View::render(__DIR__.'/views/index.php',['title'=>t('Employee workspace'),'kind'=>$kind,'search'=>$search,'rows'=>EmployeeService::rows($kind,$search),'editing'=>isset($_GET['edit'])?EmployeeService::get(record_id($_GET['edit']),$kind):null]);});
 $r->post('/employee/{kind}/save',function($p){EmployeeService::save($p['kind'],$_POST,optional_id($_POST['id']??''));return redirect('/employee?page='.urlencode($p['kind']),'Saved.');});
 $r->post('/employee/{kind}/{id}/archive',function($p){EmployeeService::archive($p['kind'],record_id($p['id']));return redirect('/employee?page='.urlencode($p['kind']),'Archived.');});
 $r->post('/employee/actions/{id}/task',function($p){$id=record_id($p['id']);$d=EmployeeService::get($id,'actions')['fields'];EmployeeService::task($id,'actions',$d['title'],$d['due']);return redirect('/employee?page=actions','Meeting action linked to a task. Repeated clicks keep the same task.');});
 $r->get('/employee/invoice/{id}',function($p){$id=record_id($p['id']);$record=EmployeeService::get($id,'invoices');return View::render(__DIR__.'/views/invoice.php',['title'=>t('Invoice'),'record'=>$record,'client'=>EmployeeService::get($record['fields']['client'],'clients')]);});
 $r->get('/employee/report',fn()=>View::render(__DIR__.'/views/report.php',['title'=>t('Employee report')]));
};
