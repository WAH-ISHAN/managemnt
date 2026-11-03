<?php
declare(strict_types=1);
final class OnboardingService {
    public static function needed(): bool {
        if (Db::setting('onboarding_complete')==='1') return false;
        $actual=array_column(Db::all("SELECT name FROM sqlite_master WHERE type='table'"),'name');
        foreach (['income','expenses','assets','uni_modules','tasks','lessons','weekly_reviews','personal_goals','career_skills','documents','focus_sessions','planner_habits','freedom_time','website_profiles'] as $table) if (in_array($table,$actual,true) && Db::one('SELECT 1 FROM '.$table.' LIMIT 1')) return false;
        return true;
    }
    public static function finish(string $name,string $institution,array $areas,bool $notifications,?string $persona=null,string $locale='en',string $template=''): void {
        $name=trim($name); if ($name==='' || mb_strlen($name)>100 || mb_strlen($institution)>150) throw new InvalidArgumentException(t('Enter your name and a valid institution.'));
        $allowed=['study','money','habits','goals','career'];
        if (array_filter($areas,fn($area)=>!is_string($area)) || array_diff($areas,$allowed)) throw new InvalidArgumentException(t('Choose a valid focus area.'));
        Db::tx(function () use ($name,$institution,$areas,$notifications,$persona,$locale,$template) {
            Db::set('name',$name); Db::set('institution',trim($institution)); Db::set('focus_areas',json_encode(array_values($areas),JSON_THROW_ON_ERROR));
            Db::set('notifications_enabled',$notifications ? '1' : '0'); Db::set('onboarding_complete','1');
            if ($persona!==null) PersonaService::apply($persona,$locale,$template,true);
        });
    }
}
