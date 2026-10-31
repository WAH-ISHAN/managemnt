<?php
declare(strict_types=1);
final class UniService {
    public const DAYS=[1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
    public static function points(): array { return json_decode(Db::setting('grade_points','{}'),true,512,JSON_THROW_ON_ERROR); }
    public static function module(int $id): array { $m=Db::one('SELECT * FROM uni_modules WHERE id=?',[$id]); if (!$m) throw new InvalidArgumentException('Choose an existing university module.'); return $m; }
    /** Save a record with an existing or newly named module as one transaction. */
    public static function withModule(array $data,callable $save): mixed {
        $module=$data['module_id'] ?? '';
        $name=$data['new_module_name'] ?? '';
        if ((!is_string($module) && !is_int($module)) || !is_string($name) || !mb_check_encoding($name,'UTF-8') || str_contains($name,"\0") || mb_strlen($name)>150) throw new InvalidArgumentException('Choose a valid module or enter a module name of up to 150 characters.');
        $name=trim($name); $hasModule=(string)$module!=='';
        if ($hasModule && $name!=='') throw new InvalidArgumentException('Choose one module option: select an existing module, or enter a new module name.');
        if (!$hasModule && $name==='') throw new InvalidArgumentException('Select a module or enter a new module name.');
        return Db::tx(function()use($module,$name,$hasModule,$save) {
            if ($hasModule) { $id=record_id($module); self::module($id); }
            else $id=Db::run('INSERT INTO uni_modules(name,credits) VALUES (?,0)',[$name]);
            return $save($id);
        });
    }
    public static function gpa(): ?float {
        $points=self::points(); $total=0; $credits=0;
        foreach (Db::all('SELECT credits,grade FROM uni_modules') as $m) if (isset($points[$m['grade']])) { $total+=$points[$m['grade']]*$m['credits']; $credits+=$m['credits']; }
        return $credits>0 ? round($total/$credits,2) : null;
    }
    public static function upcoming(int $days=7): array {
        return Db::all('SELECT a.*,m.name module FROM assignments a JOIN uni_modules m ON m.id=a.module_id WHERE a.status<>? AND a.due_date IS NOT NULL AND a.due_date<=? ORDER BY a.due_date,a.id',['done',date('Y-m-d',strtotime('+'.$days.' days'))]);
    }
    public static function exams(int $days=60): array {
        return Db::all('SELECT x.*,m.name module FROM exams x JOIN uni_modules m ON m.id=x.module_id WHERE x.date>=? AND x.date<=? ORDER BY x.date',[date('Y-m-d'),date('Y-m-d',strtotime('+'.$days.' days'))]);
    }
    public static function savePoints(string $json): void {
        try { $points=json_decode($json,true,512,JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new InvalidArgumentException('Grade points must be a JSON object, such as {"A":4,"B":3}.'); }
        if (!is_array($points) || !$points || count($points)>30 || array_is_list($points)) throw new InvalidArgumentException('Provide a grade-to-points JSON object.');
        foreach ($points as $grade=>$point) if (!is_string($grade) || !preg_match('/^[A-Za-z0-9+\- ]{1,12}$/',$grade) || !is_numeric($point) || !is_finite((float)$point) || $point<0 || $point>10) throw new InvalidArgumentException('Use short grade labels and numeric points between 0 and 10.');
        Db::set('grade_points',json_encode($points,JSON_THROW_ON_ERROR));
    }
}
