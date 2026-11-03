<?php
return function (Router $r) {
    $r->get('/settings/workspace/packs',fn()=>View::render(__DIR__.'/views/packs.php',['title'=>t('Starter packs')]));
    $r->post('/settings/workspace/packs',function() { $result=TemplatePackService::install(text_input('pack',40)); return redirect('/settings/workspace/packs',$result['installed']?t('Starter plan installed. All entries can be edited.'):t('This pack is already installed.')); });
    $r->get('/welcome',fn()=>View::render(__DIR__.'/views/index.php',['title'=>t('Welcome to LifeOS')],'welcome'));
    $r->post('/welcome',function () {
        $areas=$_POST['areas'] ?? []; if (!is_array($areas)) throw new InvalidArgumentException(t('Choose your focus areas.'));
        OnboardingService::finish(text_input('name',100),text_input('institution',150,false),$areas,isset($_POST['notifications']),isset($_POST['persona']) ? text_input('persona',30) : null,text_input('locale',2,false) ?: 'en',text_input('template',40,false));
        return redirect('/','Your workspace is ready.');
    });
    $r->get('/settings/workspace',fn()=>View::render(__DIR__.'/views/workspace.php',['title'=>t('Your workspace'),'catalog'=>PersonaService::catalog(),'profile'=>PersonaService::profile()]));
    $r->post('/settings/workspace/persona',function () {
        PersonaService::apply(text_input('persona',30),text_input('locale',2),text_input('template',40,false));
        return redirect('/settings/workspace','Persona defaults applied. Your saved records are kept.');
    });
    $r->post('/settings/workspace/modules',function () {
        $enabled=$_POST['enabled'] ?? []; $order=$_POST['order'] ?? [];
        if (!is_array($enabled)||!is_array($order)) throw new InvalidArgumentException(t('Choose valid modules and sidebar positions.'));
        PersonaService::configure($enabled,$order);
        return redirect('/settings/workspace','Modules and sidebar saved.');
    });
    $r->post('/settings/workspace/language',function () {
        $locale=text_input('locale',2);
        if (!in_array($locale,['en','si','ta'],true)) throw new InvalidArgumentException(t('Choose a supported language.'));
        Db::tx(function () use ($locale) { Db::set('locale',$locale); Db::run('UPDATE local_profiles SET locale=?,updated_at=CURRENT_TIMESTAMP WHERE id=?',[$locale,PersonaService::profileId()]); });
        return redirect(class_exists('ProfileManager') && ProfileManager::isKid() ? '/kid' : '/settings/workspace');
    });
};
