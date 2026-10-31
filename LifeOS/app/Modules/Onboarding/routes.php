<?php
return function (Router $r) {
    $r->get('/welcome',fn()=>View::render(__DIR__.'/views/index.php',['title'=>'Welcome to LifeOS'],'welcome'));
    $r->post('/welcome',function () {
        $areas=$_POST['areas'] ?? []; if (!is_array($areas)) throw new InvalidArgumentException('Choose your focus areas.');
        OnboardingService::finish(text_input('name',100),text_input('institution',150,false),$areas,isset($_POST['notifications']));
        return redirect('/salary/plan','Your workspace is ready. Start with an achievable money plan, or choose another area from the sidebar.');
    });
};
