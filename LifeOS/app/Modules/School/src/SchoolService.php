<?php
declare(strict_types=1);
final class SchoolService {
    /** Field definitions are shared by server validation and accessible forms. */
    public static function catalog(): array {
        return [
            'subjects'=>['Subjects',['name'=>'text','colour'=>'colour']],
            'homework'=>['Homework',['title'=>'text','subject'=>'subject','due'=>'date','notes'=>'optional']],
            'timetable'=>['Timetable & bag',['subject'=>'subject','day'=>'weekday','starts'=>'time','ends'=>'time','materials'=>'text']],
            'rewards'=>['Reward store',['title'=>'text','cost'=>'positive','description'=>'optional']],
            'pocket'=>['Pocket money',['title'=>'text','direction'=>'money_direction','amount'=>'money','date'=>'date']],
            'savings'=>['Piggy-bank goals',['title'=>'text','target'=>'money']],
            'tuition'=>['Tuition classes',['title'=>'text','subject'=>'subject','day'=>'weekday','starts'=>'time','fee'=>'money','due'=>'date']],
            'fees'=>['Tuition payments',['class'=>'tuition','period'=>'month','amount'=>'money','paid_on'=>'date']],
            'exams'=>['Exam countdown',['title'=>'text','type'=>'exam_type','date'=>'date']],
            'syllabus'=>['Syllabus coverage',['topic'=>'text','subject'=>'subject','exam'=>'exams']],
            'mistakes'=>['Mistakes & past papers',['title'=>'text','subject'=>'subject','attempted_on'=>'date','score'=>'percent','mistake'=>'text','correction'=>'text']],
            'reading'=>['Reading log',['book'=>'text','date'=>'date','pages'=>'positive','minutes'=>'positive','notes'=>'optional']],
            'reading_goals'=>['Reading goals',['title'=>'text','book'=>'text','target_pages'=>'positive','due'=>'date']],
            'tuition_attendance'=>['Tuition attendance',['class'=>'tuition','date'=>'date','state'=>'attendance_state']],
            'limits'=>['Study / screen reminders',['title'=>'text','activity'=>'activity','daily_minutes'=>'positive','remind_at'=>'time']],
            'time'=>['Study / screen log',['activity'=>'activity','date'=>'date','minutes'=>'positive']],
            'clubs'=>['Sports & clubs',['title'=>'text','date'=>'date','activity'=>'text','goal'=>'optional']],
            'mood'=>['Mood check-in',['date'=>'date','mood'=>'mood','note'=>'optional','share'=>'share']],
            'chores'=>['Chores & family board',['title'=>'text','assigned_to'=>'text','due'=>'date','notes'=>'optional']],
        ];
    }
    public static function definition(string $kind): array { return self::catalog()[$kind]??throw new InvalidArgumentException(t('Choose a school page.')); }
    public static function options(string $type): array {
        return match($type){
            'weekday'=>['1'=>'Monday','2'=>'Tuesday','3'=>'Wednesday','4'=>'Thursday','5'=>'Friday','6'=>'Saturday','7'=>'Sunday'],
            'money_direction'=>['in'=>'Received / saved','out'=>'Spent / withdrawn'],
            'exam_type'=>['scholarship'=>'Scholarship','ol'=>'O/L','al'=>'A/L','other'=>'Other'],
            'activity'=>['study'=>'Study','screen'=>'Screen time'],
            'mood'=>['1'=>'😞 Low','2'=>'🙁 Down','3'=>'😐 Okay','4'=>'🙂 Good','5'=>'😃 Great'],
            'share'=>['private'=>'Private','guardian'=>'Share this check-in with guardian'],
            'attendance_state'=>['present'=>'Present','absent'=>'Absent','excused'=>'Excused'],
            default=>[],
        };
    }
    public static function rows(string $kind): array { self::definition($kind); return array_map(self::decode(...),Db::all('SELECT * FROM school_records WHERE kind=? AND status<>? ORDER BY id DESC',[$kind,'archived'])); }
    private static function decode(array $row): array { $row['fields']=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR); return $row; }
    public static function get(int $id,string $kind): array { $row=Db::one('SELECT * FROM school_records WHERE id=? AND kind=? AND status<>?',[$id,$kind,'archived']); if(!$row)throw new InvalidArgumentException(t('School record not found.')); return self::decode($row); }
    public static function save(string $kind,array $input,?int $id=null): int {
        if($kind==='pocket'&&class_exists('ProfileManager')&&ProfileManager::guardians())self::guardian((string)($input['guardian']??''),(string)($input['guardian_pin']??''));
        [, $fields]=self::definition($kind); $data=[];
        foreach($fields as $key=>$type){
            $value=$input[$key]??''; if(!is_string($value)||str_contains($value,"\0")||mb_strlen($value)>2000)throw new InvalidArgumentException(t('Check ').$key.'.'); $value=trim($value);
            if($type!=='optional'&&$value==='')throw new InvalidArgumentException(t('Enter ').$key.'.');
            if($type==='date')valid_date($value);
            elseif($type==='month')valid_month($value);
            elseif($type==='time'&&!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$value))throw new InvalidArgumentException(t('Use a valid time.'));
            elseif($type==='colour'&&!preg_match('/^#[a-f0-9]{6}$/iD',$value))throw new InvalidArgumentException(t('Choose a subject colour.'));
            elseif($type==='positive')$value=int_range($value,1,100000);
            elseif($type==='money')$value=cents($value)/100;
            elseif($type==='percent')$value=percent_value($value);
            elseif(in_array($type,['subject','tuition','exams'],true)){ $value=record_id($value); self::get($value,$type==='subject'?'subjects':$type); }
            elseif(($options=self::options($type))&&!array_key_exists($value,$options))throw new InvalidArgumentException(t('Choose a valid ').$key.'.');
            $data[$key]=$value;
        }
        if(isset($data['starts'],$data['ends'])&&$data['starts']>=$data['ends'])throw new InvalidArgumentException(t('End time must be after start time.'));
        if($kind==='fees')foreach(self::rows('fees') as $row)if($row['id']!==$id&&$row['fields']['class']===$data['class']&&$row['fields']['period']===$data['period'])throw new InvalidArgumentException(t('This class already has a payment for that month.'));
        if($kind==='tuition_attendance')foreach(self::rows($kind)as $row)if($row['id']!==$id&&$row['fields']['class']===$data['class']&&$row['fields']['date']===$data['date'])throw new InvalidArgumentException(t('Attendance already exists for this class and date.'));
        if($kind==='pocket'){$balance=0;foreach(self::rows('pocket')as $p)if($p['id']!==$id)$balance+=($p['fields']['direction']==='in'?1:-1)*cents((string)$p['fields']['amount']);$balance+=($data['direction']==='in'?1:-1)*cents((string)$data['amount']);if($balance<0)throw new InvalidArgumentException(t('Pocket-money withdrawal exceeds the saved balance.'));}
        if($kind==='rewards'&&$id!==null&&Db::one('SELECT id FROM school_reward_requests WHERE reward_id=?',[$id]))throw new InvalidArgumentException(t('Rewards with requests keep their original terms. Archive and add a new reward.'));
        if($id!==null)self::get($id,$kind);
        $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        if($id===null)return Db::run('INSERT INTO school_records(kind,data) VALUES(?,?)',[$kind,$json]);
        Db::run('UPDATE school_records SET data=? WHERE id=?',[$json,$id]);return $id;
    }
    public static function complete(string $kind,int $id): void {
        if(!in_array($kind,['homework','syllabus','chores','mistakes'],true))throw new InvalidArgumentException(t('This record cannot be completed.'));
        self::get($id,$kind); Db::tx(function()use($id,$kind){$today=date('Y-m-d');Db::run("UPDATE school_records SET status='done',completed_on=COALESCE(completed_on,?) WHERE id=?",[$today,$id]);$earned=(int)Db::one('SELECT COALESCE(SUM(x.points),0) n FROM school_xp x JOIN school_records r ON r.id=x.record_id WHERE r.completed_on=?',[$today])['n'];$points=min((int)Db::setting('school_xp_'.$kind,'10'),max(0,(int)Db::setting('school_xp_daily_cap','100')-$earned));if($points>0)Db::run('INSERT OR IGNORE INTO school_xp(record_id,points) VALUES(?,?)',[$id,$points]); });
    }
    public static function pack(int $id,string $date):void{self::get($id,'timetable');valid_date($date);Db::run('INSERT OR IGNORE INTO school_bag_checks(class_id,date) VALUES(?,?)',[$id,$date]);}
    public static function alerts():array{
        $today=date('Y-m-d');$alerts=[];$add=function(string $key,string $title,string $body)use(&$alerts,$today){$alerts[]=['id'=>hash('sha256','school:'.$key.':'.$today),'title'=>$title,'body'=>$body,'route'=>'/school','level'=>'upcoming'];};
        foreach(self::rows('homework')as $r)if($r['status']==='open'&&$r['fields']['due']<=$today)$add('hw'.$r['id'],'Homework due',$r['fields']['title']);
        foreach(self::rows('chores')as $r)if($r['status']==='open'&&$r['fields']['due']<=$today)$add('chore'.$r['id'],'Chore due',$r['fields']['title']);
        foreach(self::rows('limits')as $r)if($r['fields']['remind_at']<=date('H:i'))$add('limit'.$r['id'],'Time for a pause',$r['fields']['title']);
        foreach(self::rows('tuition')as $r){$d=$r['fields'];$paid=0;foreach(self::rows('fees')as $f)if($f['fields']['class']===$r['id']&&$f['fields']['period']===date('Y-m'))$paid+=$f['fields']['amount'];if($d['due']<=$today&&$paid<$d['fee'])$add('tuition'.$r['id'],'Tuition fee due',$d['title'].' · '.money($d['fee']-$paid));}
        return $alerts;
    }
    public static function settings(array $d):void{
        $guardians=class_exists('ProfileManager')?ProfileManager::guardians():[];
        if($guardians)self::guardian((string)($d['guardian']??''),(string)($d['guardian_pin']??''));else{$hash=Db::setting('pin_hash');if($hash===''||!password_verify((string)($d['guardian_pin']??''),$hash))throw new InvalidArgumentException(t('Set and verify a profile PIN to change XP rules.'));}
        $valid=[];foreach(['homework','chores','syllabus','mistakes']as $kind)$valid['school_xp_'.$kind]=(string)int_range($d[$kind]??'',1,100);$valid['school_xp_daily_cap']=(string)int_range($d['daily_cap']??'',1,1000);$valid['school_level_every']=(string)int_range($d['level_every']??'',10,1000);$valid['school_badge_at']=(string)int_range($d['badge_at']??'',10,10000);$title=$d['badge_title']??'';if(!is_string($title)||trim($title)===''||mb_strlen($title)>80)throw new InvalidArgumentException(t('Enter a badge name.'));$valid['school_badge_title']=trim($title);Db::tx(function()use($valid){foreach($valid as $key=>$v)Db::set($key,$v);});
    }
    public static function redeem(int $id,string $guardian,string $pin):void{self::guardian($guardian,$pin);Db::tx(function()use($id,$guardian){$r=Db::one("SELECT id FROM school_reward_requests WHERE id=? AND status='approved'",[$id]);if(!$r||Db::one('SELECT request_id FROM school_reward_redemptions WHERE request_id=?',[$id]))throw new InvalidArgumentException(t('Reward is not approved or was already redeemed.'));Db::run('INSERT INTO school_reward_redemptions(request_id,guardian_id) VALUES(?,?)',[$id,$guardian]);Db::run('INSERT INTO school_guardian_audit(guardian_id,action,record_id) VALUES(?,?,?)',[$guardian,'reward-redeemed',$id]);});}
    public static function streak():int{$dates=array_column(Db::all("SELECT DISTINCT completed_on FROM school_records WHERE status='done' AND completed_on IS NOT NULL"),'completed_on');$date=date('Y-m-d');if(!in_array($date,$dates,true))$date=date('Y-m-d',strtotime('-1 day'));$n=0;while(in_array($date,$dates,true)){$n++;$date=date('Y-m-d',strtotime($date.' -1 day'));}return $n;}
    public static function archive(string $kind,int $id): void { self::get($id,$kind);if($kind==='pocket'&&class_exists('ProfileManager')&&ProfileManager::guardians())throw new InvalidArgumentException(t('Use a guardian-approved correction rather than archiving pocket-money history.')); if(in_array($kind,['subjects','tuition','exams'],true)){foreach(Db::all("SELECT data FROM school_records WHERE status<>'archived'")as $row){$d=json_decode($row['data'],true);$key=match($kind){'subjects'=>'subject','tuition'=>'class','exams'=>'exam'};if(($d[$key]??null)===$id)throw new InvalidArgumentException(t('Archive linked records first.'));}} Db::run("UPDATE school_records SET status='archived' WHERE id=?",[$id]); }
    public static function xp(): array { $earned=(int)(Db::one('SELECT COALESCE(SUM(points),0) n FROM school_xp')['n']);$spent=(int)Db::one("SELECT COALESCE(SUM(cost),0) n FROM school_reward_requests WHERE status='approved'")['n'];return ['earned'=>$earned,'available'=>$earned-$spent,'level'=>1+intdiv($earned,max(10,(int)Db::setting('school_level_every','100'))),'badge'=>$earned>=(int)Db::setting('school_badge_at','500')?Db::setting('school_badge_title','Learning champion'):($earned>=100?'Steady learner':($earned>=10?'First step':'Start your journey'))]; }
    public static function requestReward(int $id):void { $row=self::get($id,'rewards'); if(Db::one("SELECT id FROM school_reward_requests WHERE reward_id=? AND status='pending'",[$id]))throw new InvalidArgumentException(t('A request is already waiting for this reward.'));Db::run('INSERT INTO school_reward_requests(reward_id,cost) VALUES(?,?)',[$id,$row['fields']['cost']]); }
    public static function guardian(string $guardian,string $pin):void {
        if(!class_exists('ProfileManager'))throw new InvalidArgumentException(t('Link a guardian profile first.'));
        $ids=array_column(ProfileManager::guardians(),'id');if(!in_array($guardian,$ids,true))throw new InvalidArgumentException(t('Choose a linked guardian.'));ProfileManager::verify($guardian,$pin,true);
    }
    public static function decideReward(int $id,string $decision,string $guardian,string $pin):void {
        if(!in_array($decision,['approved','rejected'],true))throw new InvalidArgumentException(t('Choose approve or reject.'));self::guardian($guardian,$pin);
        Db::tx(function()use($id,$decision,$guardian){$row=Db::one("SELECT * FROM school_reward_requests WHERE id=? AND status='pending'",[$id]);if(!$row)throw new InvalidArgumentException(t('This request has already been handled.'));if($decision==='approved'&&self::xp()['available']<(int)$row['cost'])throw new InvalidArgumentException(t('Not enough earned XP.'));Db::run('UPDATE school_reward_requests SET status=?,guardian_id=?,decided_at=CURRENT_TIMESTAMP WHERE id=?',[$decision,$guardian,$id]);Db::run('INSERT INTO school_guardian_audit(guardian_id,action,record_id) VALUES(?,?,?)',[$guardian,'reward-'.$decision,$id]);});
    }
    public static function summary(string $date):array {
        valid_date($date);$start=(new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');$end=(new DateTimeImmutable($start))->modify('+6 days')->format('Y-m-d');
        $out=['start'=>$start,'end'=>$end,'completed'=>[],'reading_pages'=>0,'study_minutes'=>0,'shared_moods'=>[]];
        foreach(Db::all("SELECT * FROM school_records WHERE status<>'archived'")as $raw){$row=self::decode($raw);$d=$row['fields'];if($row['completed_on']!==null&&$row['completed_on']>=$start&&$row['completed_on']<=$end)$out['completed'][]=$d['title']??$d['topic']??'Learning activity';$day=$d['date']??'';if($day<$start||$day>$end)continue;if($row['kind']==='reading')$out['reading_pages']+=(int)$d['pages'];if($row['kind']==='time'&&$d['activity']==='study')$out['study_minutes']+=(int)$d['minutes'];if($row['kind']==='mood'&&$d['share']==='guardian')$out['shared_moods'][]=['date'=>$day,'mood'=>$d['mood']];}
        return $out;
    }
}
