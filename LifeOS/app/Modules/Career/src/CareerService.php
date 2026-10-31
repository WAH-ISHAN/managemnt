<?php
declare(strict_types=1);
final class CareerService {
    public const COURSE_STATUSES=['planned'=>'Planned','in_progress'=>'In progress','paused'=>'Paused','completed'=>'Completed'];
    public const APPLICATION_STATUSES=['saved'=>'Saved','applied'=>'Applied','interview'=>'Interview','offered'=>'Offered','accepted'=>'Accepted','rejected'=>'Rejected','withdrawn'=>'Withdrawn'];
    private static function text(array $d,string $key,int $max=200,bool $required=false): string { $value=$d[$key] ?? ''; if (!is_string($value)) throw new InvalidArgumentException('Invalid '.$key.'.'); $value=trim($value); if (($required && $value==='') || mb_strlen($value)>$max) throw new InvalidArgumentException('Enter a valid '.str_replace('_',' ',$key).' (up to '.$max.' characters).'); return $value; }
    private static function date(mixed $value): ?string { if ($value===null || $value==='') return null; if (!is_string($value)) throw new InvalidArgumentException('Choose a valid career date.'); return valid_date($value); }
    public static function row(string $table,int $id): array { if (!in_array($table,['career_skills','career_courses','career_applications'],true)) throw new InvalidArgumentException('Invalid career record.'); $row=Db::one('SELECT * FROM '.$table.' WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException('Career record not found.'); return $row; }
    public static function saveSkill(?int $id,array $d): int {
        $name=self::text($d,'name',150,true); $progress=int_range($d['progress'] ?? 0,0,100); $due=self::date($d['target_date'] ?? ''); $notes=self::text($d,'notes',3000);
        if ($id) self::row('career_skills',$id); if (Db::one('SELECT id FROM career_skills WHERE name=? AND id<>?',[$name,$id ?? 0])) throw new InvalidArgumentException('A skill with this name already exists.');
        if ($id) { Db::run('UPDATE career_skills SET name=?,progress=?,target_date=?,notes=? WHERE id=?',[$name,$progress,$due,$notes,$id]); return $id; }
        return Db::run('INSERT INTO career_skills(name,progress,target_date,notes) VALUES (?,?,?,?)',[$name,$progress,$due,$notes]);
    }
    public static function saveCourse(?int $id,array $d): int {
        $name=self::text($d,'name',200,true); $provider=self::text($d,'provider'); $resource=self::text($d,'resource',1000); $skill=optional_id($d['skill_id'] ?? ''); if ($skill) self::row('career_skills',$skill);
        $progress=int_range($d['progress'] ?? 0,0,100); $status=$d['status'] ?? 'planned'; if (!is_string($status) || !isset(self::COURSE_STATUSES[$status])) throw new InvalidArgumentException('Choose a valid course status.');
        if ($status==='completed') $progress=100; elseif ($progress===100) throw new InvalidArgumentException('Choose Completed for a 100% course, or lower its progress to reopen it.');
        $values=[$name,$provider,$resource,$skill,$progress,$status,self::date($d['due_date'] ?? ''),self::text($d,'notes',3000)];
        if ($id) { self::row('career_courses',$id); Db::run('UPDATE career_courses SET name=?,provider=?,resource=?,skill_id=?,progress=?,status=?,due_date=?,notes=? WHERE id=?',[...$values,$id]); return $id; }
        return Db::run('INSERT INTO career_courses(name,provider,resource,skill_id,progress,status,due_date,notes) VALUES (?,?,?,?,?,?,?,?)',$values);
    }
    public static function saveApplication(?int $id,array $d): int {
        $status=$d['status'] ?? 'saved'; if (!is_string($status) || !isset(self::APPLICATION_STATUSES[$status])) throw new InvalidArgumentException('Choose a valid application status.');
        $interview=self::text($d,'interview_at',30); if ($interview!=='') { $interview=str_replace('T',' ',$interview); $parsed=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$interview); if (!$parsed || $parsed->format('Y-m-d H:i')!==$interview) throw new InvalidArgumentException('Choose a valid interview date and time.'); } else $interview=null;
        $values=[self::text($d,'organization',200,true),self::text($d,'role',200,true),self::text($d,'resource',1000),$status,self::date($d['applied_date'] ?? ''),self::date($d['deadline'] ?? ''),$interview,self::text($d,'notes',5000)];
        if ($id) { self::row('career_applications',$id); Db::run('UPDATE career_applications SET organization=?,role=?,resource=?,status=?,applied_date=?,deadline=?,interview_at=?,notes=? WHERE id=?',[...$values,$id]); return $id; }
        return Db::run('INSERT INTO career_applications(organization,role,resource,status,applied_date,deadline,interview_at,notes) VALUES (?,?,?,?,?,?,?,?)',$values);
    }
    public static function remove(string $table,int $id): void { self::row($table,$id); Db::run('DELETE FROM '.$table.' WHERE id=?',[$id]); }
    public static function upcomingInterviews(int $days=30): array { return Db::all("SELECT * FROM career_applications WHERE interview_at>=? AND interview_at<=? AND status NOT IN ('rejected','withdrawn','accepted') ORDER BY interview_at",[date('Y-m-d H:i'),date('Y-m-d H:i',strtotime('+'.$days.' days'))]); }
}
