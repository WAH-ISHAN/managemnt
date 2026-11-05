<?php
declare(strict_types=1);
/** Editable starting plans. No completed history, grades, balances or exam dates are invented. */
final class TemplatePackService {
    public static function catalog(): array {
        return [
            'hnd'=>['name'=>'HND student','persona'=>'uni','goal'=>'Complete this semester with a consistent study plan','tasks'=>['Enter your real modules, credits and timetable','Add assignment deadlines from your course portal','Plan this week’s study sessions'],'habits'=>['Review lecture notes','Practise one course problem'],'steps'=>['Enter semester deadlines','Break the first assignment into steps','Review progress each week'],'modules'=>['Programming foundations','Database fundamentals','Professional practice']],
            'software_engineer'=>['name'=>'Software engineer employee','persona'=>'employee','goal'=>'Build a sustainable work and learning routine','tasks'=>['Choose this week’s three work priorities','Break the next work item into small steps','Plan one learning session'],'habits'=>['Write a short work log','Take a screen break'],'steps'=>['Choose one skill to improve','Complete a small practice project','Review work and learning each week'],'modules'=>[]],
        ];
    }
    public static function installed(string $id): bool { return (bool)Db::one('SELECT 1 FROM starter_pack_installs WHERE pack_id=?',[$id]); }
    public static function install(string $id): array {
        $pack=self::catalog()[$id]??null; if(!$pack) throw new InvalidArgumentException(t('Choose a valid starter pack.'));
        $roles=PersonaService::selectedRoles();
        if($roles&&!in_array($pack['persona'],$roles,true))throw new InvalidArgumentException(t('Choose a starter pack that matches your persona.'));
        return Db::tx(function()use($id,$pack) {
            if(self::installed($id)) return ['installed'=>false,'tasks'=>0,'habits'=>0];
            PersonaService::apply(array_values(array_unique(array_merge(PersonaService::selectedRoles(),[$pack['persona']]))),Db::setting('locale','en'),$id,true);
            Db::run('INSERT INTO starter_pack_installs(pack_id,version) VALUES (?,1)',[$id]);
            foreach($pack['tasks'] as $title) Db::run('INSERT INTO tasks(title,source) VALUES (?,?)',[t($title),'starter:'.$id]);
            foreach($pack['habits'] as $title) Db::run('INSERT INTO planner_habits(title,notes) VALUES (?,?)',[t($title),t('Editable starter habit from').' '.t($pack['name'])]);
            $goal=Db::run("INSERT INTO personal_goals(title,category,notes) VALUES (?,'learning',?)",[t($pack['goal']),t('Editable starter plan from').' '.t($pack['name'])]);
            foreach($pack['steps'] as $sort=>$title) Db::run('INSERT INTO personal_goal_steps(goal_id,title,sort) VALUES (?,?,?)',[$goal,t($title),$sort]);
            foreach($pack['modules'] as $index=>$name) Db::run('INSERT INTO uni_modules(code,name,credits,semester) VALUES (?,?,0,?)',['STARTER-'.($index+1),t($name).' ('.t('edit to match your course').')',t('Starter')]);
            return ['installed'=>true,'tasks'=>count($pack['tasks']),'habits'=>count($pack['habits'])];
        });
    }
}
