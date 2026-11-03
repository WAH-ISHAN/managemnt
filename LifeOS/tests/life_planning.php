<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require ROOT.'/app/Core/Router.php';
require ROOT.'/app/Core/View.php';
$checks=0;
function life_check(bool $ok,string $label): void { global $checks; if(!$ok) throw new RuntimeException($label); $checks++; }
function life_request(Router $router,string $method,string $path,array $data=[]): string { $_POST=$data; $_GET=[]; parse_str(parse_url($path,PHP_URL_QUERY)??'',$_GET); $_SERVER['REQUEST_URI']=$path; ob_start(); try { $router->dispatch($method,parse_url($path,PHP_URL_PATH)); return ob_get_clean(); } catch(Throwable $e) { ob_end_clean(); throw $e; } }
$_SESSION=['csrf'=>'test-token']; $_SERVER['HTTP_HX_REQUEST']='true';
$file=sys_get_temp_dir().'/lifeos-life-planning-'.bin2hex(random_bytes(6)).'.db';
try {
    Db::init($file); $dirs=array_merge([ROOT.'/app/Core/migrations'],array_map(fn($m)=>$m['path'].'/migrations',Modules::discover(ROOT.'/app/Modules'))); Migrator::run($dirs); $router=new Router(); (require ROOT.'/app/Modules/LifePlanning/routes.php')($router); (require ROOT.'/app/Modules/Planner/routes.php')($router);
    life_request($router,'POST','/life/plan',['title'=>'Avurudu','year'=>'2027','start_on'=>'2027-04-13','end_on'=>'2027-04-14','budget'=>'50000','notes'=>'Family travel']);
    life_request($router,'POST','/life/plan/item',['plan_id'=>'1','title'=>'Gift budget','kind'=>'gift','amount'=>'12000']);
    life_request($router,'POST','/life/seettu',['name'=>'Family circle','contribution'=>'5000','frequency'=>'monthly','rounds'=>'12','start_on'=>'2027-01-01','notes'=>'']);
    life_request($router,'POST','/life/seettu/payment',['seettu_id'=>'1','round_no'=>'1','paid_on'=>'2027-01-01','amount'=>'5000']);
    life_request($router,'POST','/life/utility',['provider'=>'CEB','account_ref'=>'A1','frequency'=>'monthly','due_day'=>'15','notes'=>'']);
    life_request($router,'POST','/life/vehicle',['name'=>'Car','registration'=>'WP-1','make_model'=>'Test','insurance_expiry'=>'2027-02-01','licence_expiry'=>'2027-03-01','emission_expiry'=>'2027-04-01','notes'=>'']);
    life_request($router,'POST','/life/metal',['name'=>'Ring','kind'=>'jewellery','weight'=>'4.25','unit'=>'g','purity'=>'22','acquired_on'=>'2027-01-01','cost'=>'100000','notes'=>'']);
    life_request($router,'POST','/life/deposit',['name'=>'FD','kind'=>'fixed_deposit','principal'=>'100000','rate'=>'10','rate_type'=>'annual','start_on'=>'2027-01-01','maturity_on'=>'2028-01-01','notes'=>'']);
    life_request($router,'POST','/life/rate',['code'=>'USD','rate_lkr'=>'320','as_of'=>'2027-01-01','source'=>'Test']);
    life_request($router,'POST','/life/event',['name'=>'Wedding','kind'=>'wedding','event_on'=>'2027-06-01','notes'=>'']);
    life_request($router,'POST','/life/health',['kind'=>'sleep','logged_on'=>'2027-01-01','value'=>'7.5','unit'=>'hours','notes'=>'']);
    life_request($router,'POST','/life/meal',['meal_date'=>'2027-01-01','slot'=>'dinner','title'=>'Rice','servings'=>'2','notes'=>'']);
    life_request($router,'POST','/life/grocery',['item'=>'Rice','quantity'=>'5','unit'=>'kg','estimated_cost'=>'1500','needed_on'=>'2027-01-01']);
    life_request($router,'POST','/life/wishlist',['title'=>'Bicycle','amount'=>'50000','revisit_on'=>'2027-02-01','notes'=>'']);
    life_request($router,'POST','/life/contact',['name'=>'Amma','phone'=>'071','email'=>'a@example.test','birthday'=>'1970-01-01','next_followup'=>'2027-01-02','notes'=>'']);
    life_request($router,'POST','/life/debt',['name'=>'Card','balance'=>'20000','annual_rate'=>'24','minimum_payment'=>'1000','notes'=>'']);
    $page=life_request($router,'GET','/life');
    life_check(str_contains($page,'Avurudu') && str_contains(life_request($router,'GET','/life?page=seettu'),'Family circle') && str_contains(life_request($router,'GET','/life?page=utilities'),'CEB'),'Relevant life planning pages render saved records');
    foreach(array_keys(LifePlanningService::catalog())as $kind)life_check(str_contains(life_request($router,'GET','/life?page='.$kind),'<h1>Life &amp; Sri Lanka</h1>'),'Life page renders '.$kind);
    life_check(count(Db::all('SELECT * FROM life_festival_items'))===1 && count(Db::all('SELECT * FROM life_seettu_payments'))===1,'Planning checklist and seettu payment persist');
    life_check(count(Db::all('SELECT * FROM life_vehicles'))===1 && count(Db::all('SELECT * FROM life_metals'))===1 && count(Db::all('SELECT * FROM life_deposits'))===1,'Asset records persist');
    life_check(count(Db::all('SELECT * FROM life_health_logs'))===1 && count(Db::all('SELECT * FROM life_groceries'))===1 && count(Db::all('SELECT * FROM life_contacts'))===1,'Health, household and people records persist');
    life_check(count(Db::all('SELECT * FROM life_debts'))===1,'Debt strategy records persist');
    life_request($router,'POST','/planner/holidays/holiday',['year'=>'2027','date'=>'2027-04-14','title_en'=>'New Year','title_si'=>'අලුත් අවුරුදු','title_ta'=>'புத்தாண்டு','jurisdiction'=>'Sri Lanka','is_public'=>'1','is_bank'=>'1']);
    life_request($router,'POST','/planner/holidays/term',['year'=>'2027','authority'=>'Test school','label'=>'Term 1','starts_on'=>'2027-01-01','ends_on'=>'2027-03-31','source_url'=>'']);
    $calendar=SriLankaCalendarService::listing(2027); life_check(count($calendar['holidays'])===1 && count($calendar['school_terms'])===1,'Holiday and school-term entries persist for a new year');
    Db::close();
} finally { Db::close(); foreach([$file,$file.'-wal',$file.'-shm'] as $temporary) if(is_file($temporary)) unlink($temporary); }
echo "$checks life planning checks passed.\n";
