<?php
declare(strict_types=1);
/** Persona and module configuration inside one isolated local database. */
final class PersonaService {
    public static function profileId(): string { return 'default'; }
    public static function personas(): array {
        return [
            'employee'=>['name'=>'Employee','description'=>t('Work, salary, goals and time for yourself.'),'layout'=>'work','modules'=>['Employee','Work','Salary','Investments','Career','Growth','Freedom','Focus','Documents','LifeAdmin','LifePlanning','Lessons']],
            'uni'=>['name'=>'University student','description'=>t('Modules, assignments, study and a student budget.'),'layout'=>'study','modules'=>['Uni','StudyTools','Salary','Focus','Growth','Documents','Lessons','Career','Freedom','LifePlanning']],
            'school'=>['name'=>'School student','description'=>t('A simple space for tasks, study and goals.'),'layout'=>'simple','modules'=>['Focus','Growth','Lessons','School']],
            'freelancer'=>['name'=>'Freelancer','description'=>t('Projects, income, clients and career growth.'),'layout'=>'work','modules'=>['Work','Salary','Investments','Career','Documents','Growth','Freedom','Websites','Focus']],
            'business'=>['name'=>'Business owner','description'=>t('Business projects, money and your websites.'),'layout'=>'money','modules'=>['Work','Salary','Investments','SmallBusiness','Websites','WebAdmin','Documents','LifeAdmin','Growth']],
            'job_seeker'=>['name'=>'Job seeker','description'=>t('Applications, skills, focus and your next step.'),'layout'=>'career','modules'=>['Career','Growth','Documents','Focus','Salary','Freedom','Lessons']],
        ];
    }
    public static function templates(): array {
        return ['hnd'=>['name'=>'HND student','persona'=>'uni'],'grade_11'=>['name'=>'Grade 11','persona'=>'school'],'software_engineer'=>['name'=>'Software engineer employee','persona'=>'employee']];
    }
    public static function catalog(): array {
        return array_map(static function ($m) { $m['id']=basename($m['path']); return $m; },Modules::discover(ROOT.'/app/Modules'));
    }
    public static function profile(): ?array { return Db::one('SELECT * FROM local_profiles WHERE id=?',[self::profileId()]); }
    public static function enabled(string $moduleId): bool {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D',$moduleId) || !is_file(ROOT.'/app/Modules/'.$moduleId.'/module.json')) return false;
        if (in_array($moduleId,['Dashboard','Planner','Settings','Onboarding','Insights'],true)) return true;
        $row=Db::one('SELECT enabled FROM modules WHERE profile_id=? AND module_id=?',[self::profileId(),$moduleId]);
        // Existing installations retain all their modules until they choose a persona.
        return $row===null ? self::profile()===null : (bool)$row['enabled'];
    }
    public static function apply(string $persona,string $locale='en',string $template='',bool $inTransaction=false): void {
        $definition=self::personas()[$persona] ?? null;
        if (!$definition || !in_array($locale,['en','si','ta'],true)) throw new InvalidArgumentException(t('Choose a valid persona and language.'));
        $pack=self::templates()[$template] ?? null;
        if ($template!=='' && (!$pack || $pack['persona']!==$persona)) throw new InvalidArgumentException(t('Choose a starter pack that matches your persona.'));
        $catalog=self::catalog();
        $save=function () use ($persona,$locale,$template,$definition,$catalog) {
            Db::run('INSERT INTO local_profiles(id,name,persona,locale,template,dashboard_layout) VALUES (?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET name=excluded.name,persona=excluded.persona,locale=excluded.locale,template=excluded.template,dashboard_layout=excluded.dashboard_layout,updated_at=CURRENT_TIMESTAMP',[self::profileId(),Db::setting('name'),$persona,$locale,$template,$definition['layout']]);
            $common=$persona==='school'?[]:['LifePlanning','Platform','WorkRoles','Intelligence'];
            $enabled=array_merge(['Dashboard','Planner','Settings','Onboarding','Insights','Automation'],$definition['modules'],$common);
            foreach ($catalog as $m) Db::run('INSERT INTO modules(profile_id,module_id,enabled,sort_order) VALUES (?,?,?,?) ON CONFLICT(profile_id,module_id) DO UPDATE SET enabled=excluded.enabled,sort_order=excluded.sort_order',[self::profileId(),$m['id'],(int)in_array($m['id'],$enabled,true),$m['order'] ?? 50]);
            Db::set('locale',$locale);
        };
        if ($inTransaction) $save(); else Db::tx($save);
    }
    public static function configure(array $enabled,array $order): void {
        $catalog=self::catalog(); $ids=array_column($catalog,'id');
        if (array_filter($enabled,fn($v)=>!is_string($v)) || array_diff($enabled,$ids) || array_diff(array_keys($order),$ids)) throw new InvalidArgumentException(t('Choose valid modules.'));
        $positions=[];
        foreach ($catalog as $m) $positions[$m['id']]=int_range($order[$m['id']] ?? $m['order'] ?? 50,0,999);
        Db::tx(function () use ($catalog,$enabled,$positions) {
            if (!self::profile()) Db::run("INSERT INTO local_profiles(id,name,persona) VALUES (?,?,'custom')",[self::profileId(),Db::setting('name')]);
            foreach ($catalog as $m) Db::run('INSERT INTO modules(profile_id,module_id,enabled,sort_order) VALUES (?,?,?,?) ON CONFLICT(profile_id,module_id) DO UPDATE SET enabled=excluded.enabled,sort_order=excluded.sort_order',[self::profileId(),$m['id'],(int)(in_array($m['id'],['Dashboard','Planner','Settings','Onboarding','Insights'],true)||in_array($m['id'],$enabled,true)),$positions[$m['id']]]);
        });
    }
    public static function menu(array $catalog): array {
        if (class_exists('ProfileManager') && ProfileManager::isKid()) return array_values(array_filter($catalog,fn($m)=>in_array(basename($m['path']),['Planner','Focus','School'],true)&&self::enabled(basename($m['path']))));

        $rows=Db::all('SELECT module_id,enabled,sort_order FROM modules WHERE profile_id=?',[self::profileId()]);
        if (!$rows) return $catalog;
        $order=array_column($rows,'sort_order','module_id');
        $out=array_values(array_filter($catalog,fn($m)=>self::enabled(basename($m['path']))));
        usort($out,fn($a,$b)=>($order[basename($a['path'])] ?? 999)<=>($order[basename($b['path'])] ?? 999) ?: strcmp($a['name'],$b['name']));
        return $out;
    }
    public static function routeEnabled(string $path): bool {
        $aliases=['/expenses'=>'Salary','/goals'=>'Investments','/tasks'=>'Lessons','/review'=>'Lessons','/why/net-worth'=>'Salary','/settings/grades'=>'Uni','/settings/rules'=>'Salary','/settings/accounts'=>'Salary','/settings/categories'=>'Salary','/settings/budgets'=>'Salary'];
        foreach ($aliases as $prefix=>$id) if ($path===$prefix || str_starts_with($path,$prefix.'/')) return self::enabled($id);
        $catalog=self::catalog(); usort($catalog,fn($a,$b)=>strlen($b['route'])<=>strlen($a['route']));
        foreach ($catalog as $m) if ($m['route']!=='/' && ($path===$m['route'] || str_starts_with($path,$m['route'].'/'))) return self::enabled($m['id']);
        return true;
    }
}
