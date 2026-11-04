<?php
declare(strict_types=1);
final class UniService {
    public const DAYS=[1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
    public static function points(): array { return json_decode(Db::setting('grade_points','{}'),true,512,JSON_THROW_ON_ERROR); }
    public static function module(int $id): array { $m=Db::one('SELECT * FROM uni_modules WHERE id=?',[$id]); if (!$m) throw new InvalidArgumentException(t('Choose an existing university module.')); return $m; }
    /** Save a record with an existing or newly named module as one transaction. */
    public static function withModule(array $data,callable $save): mixed {
        $module=$data['module_id'] ?? '';
        $name=$data['new_module_name'] ?? '';
        if ((!is_string($module) && !is_int($module)) || !is_string($name) || !mb_check_encoding($name,'UTF-8') || str_contains($name,"\0") || mb_strlen($name)>150) throw new InvalidArgumentException(t('Choose a valid module or enter a module name of up to 150 characters.'));
        $name=trim($name); $hasModule=(string)$module!=='';
        if ($hasModule && $name!=='') throw new InvalidArgumentException(t('Choose one module option: select an existing module, or enter a new module name.'));
        if (!$hasModule && $name==='') throw new InvalidArgumentException(t('Select a module or enter a new module name.'));
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
    public static function targetGpa(mixed $input): array {
        if (!is_string($input) || !preg_match('/^(?:\d{1,2})(?:\.\d{1,2})?$/D',trim($input))) throw new InvalidArgumentException(t('Enter a target GPA with up to two decimal places.'));
        $target=(float)trim($input);
        $points=self::points();
        if (!$points) return ['status'=>'no_scale','target'=>$target];
        $max=max(array_map('floatval',array_values($points)));
        $earned=0.0; $graded=0; $remaining=0;
        foreach (Db::all('SELECT credits,grade FROM uni_modules') as $module) {
            $credits=(int)$module['credits'];
            if ($credits===0) continue;
            if ($module['grade']!==null && $module['grade']!=='' && array_key_exists($module['grade'],$points)) {
                $earned+=(float)$points[$module['grade']]*$credits; $graded+=$credits;
            } else $remaining+=$credits;
        }
        $base=['target'=>$target,'graded_credits'=>$graded,'remaining_credits'=>$remaining,'current_gpa'=>$graded ? $earned/$graded : null,'max_points'=>$max];
        if ($graded+$remaining===0) return $base+['status'=>'no_credits'];
        if ($remaining===0) return $base+['status'=>$earned/$graded+1e-9 >= $target ? 'met' : 'unreachable'];
        $required=($target*($graded+$remaining)-$earned)/$remaining;
        if ($required<=0) return $base+['status'=>'secured','required_average'=>0.0,'minimum_grade'=>null];
        if ($required>$max+1e-9) return $base+['status'=>'unreachable','required_average'=>$required,'minimum_grade'=>null];
        $qualifying=[];
        foreach ($points as $grade=>$value) if ((float)$value+1e-9 >= $required) $qualifying[$grade]=(float)$value;
        asort($qualifying,SORT_NUMERIC);
        return $base+['status'=>'possible','required_average'=>$required,'minimum_grade'=>array_key_first($qualifying)];
    }
    public static function upcoming(int $days=7): array {
        return Db::all('SELECT a.*,m.name module FROM assignments a JOIN uni_modules m ON m.id=a.module_id WHERE a.status<>? AND a.due_date IS NOT NULL AND a.due_date<=? ORDER BY a.due_date,a.id',['done',date('Y-m-d',strtotime('+'.$days.' days'))]);
    }
    public static function exams(int $days=60): array {
        return Db::all('SELECT x.*,m.name module FROM exams x JOIN uni_modules m ON m.id=x.module_id WHERE x.date>=? AND x.date<=? ORDER BY x.date',[date('Y-m-d'),date('Y-m-d',strtotime('+'.$days.' days'))]);
    }
    public static function savePoints(string $json): void {
        try { $points=json_decode($json,true,512,JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new InvalidArgumentException(t('Grade points must be a JSON object, such as {"A":4,"B":3}.')); }
        if (!is_array($points) || !$points || count($points)>30 || array_is_list($points)) throw new InvalidArgumentException(t('Provide a grade-to-points JSON object.'));
        foreach ($points as $grade=>$point) if (!is_string($grade) || !preg_match('/^[A-Za-z0-9+\- ]{1,12}$/',$grade) || !is_numeric($point) || !is_finite((float)$point) || $point<0 || $point>10) throw new InvalidArgumentException(t('Use short grade labels and numeric points between 0 and 10.'));
        Db::set('grade_points',json_encode($points,JSON_THROW_ON_ERROR));
    }
}
