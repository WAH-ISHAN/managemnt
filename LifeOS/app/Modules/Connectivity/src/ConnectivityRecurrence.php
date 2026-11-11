<?php
declare(strict_types=1);
/** RFC5545 sets expanded locally into stable, reviewable occurrence identities. */
final class ConnectivityRecurrence {
    private static function library(): void {
        foreach (['RRuleInterface','RRuleTrait','RfcParser','RRule','RSet'] as $file) require_once ROOT.'/app/Core/libraries/rrule/'.$file.'.php';
    }
    private static function property(string $line): array {
        [$key,$value]=explode(':',$line,2); $bits=explode(';',$key); $name=strtoupper(array_shift($bits)); $params=[];
        foreach ($bits as $bit) { $p=explode('=',$bit,2); if(count($p)===2)$params[strtoupper($p[0])]=trim($p[1],'"'); }
        return [$name,$value,$params];
    }
    private static function date(string $value,array $params=[]): DateTimeImmutable {
        $zone=str_ends_with($value,'Z')?'UTC':($params['TZID'] ?? 'Asia/Colombo');
        try { $tz=new DateTimeZone($zone); } catch (Throwable) { throw new InvalidArgumentException(t('Unknown calendar timezone.')); }
        $format=strlen($value)===8?'!Ymd':(str_ends_with($value,'Z')?'!Ymd\THis\Z':'!Ymd\THis');
        $date=DateTimeImmutable::createFromFormat($format,$value,$tz); $error=DateTimeImmutable::getLastErrors();
        if (!$date || ($error && ($error['warning_count'] || $error['error_count']))) throw new InvalidArgumentException(t('Calendar contains an invalid date.'));
        return $date;
    }
    private static function identity(DateTimeInterface $date): string { return $date->format('U'); }
    public static function expand(string $ics,?string $from=null,?string $until=null,int $limit=500): string {
        if ($limit<1 || $limit>5000) throw new InvalidArgumentException(t('Invalid calendar event limit.'));
        if (!preg_match('/^(?:RRULE|RDATE|EXDATE|RECURRENCE-ID)[;:]/mi',$ics)) return $ics;
        self::library();
        $from=valid_date($from ?? date('Y-m-d')); $until=valid_date($until ?? (new DateTimeImmutable($from))->modify('+1 year')->format('Y-m-d'));
        $lower=new DateTimeImmutable($from,new DateTimeZone('Asia/Colombo')); $upper=new DateTimeImmutable($until.' 23:59:59',new DateTimeZone('Asia/Colombo'));
        if($upper<$lower || $lower->diff($upper)->days>732)throw new InvalidArgumentException(t('Choose a calendar range of at most two years.'));
        $ics=preg_replace('/\r?\n[ \t]/','',$ics); $blocks=[]; $current=null; $alarm=false;
        foreach(preg_split('/\r\n|\n|\r/',$ics)as $line){
            if($line==='BEGIN:VEVENT'){if($current!==null)throw new InvalidArgumentException(t('Nested calendar events are unsupported.'));$current=[];continue;}
            if($line==='END:VEVENT'){if($current===null)throw new InvalidArgumentException(t('Incomplete calendar event.'));$blocks[]=$current;$current=null;continue;}
            if($current===null)continue;
            if($line==='BEGIN:VALARM'){$alarm=true;continue;}if($line==='END:VALARM'){$alarm=false;continue;}if($alarm)continue;
            if(str_contains($line,':'))$current[]=self::property($line);
        }
        if($current!==null || count($blocks)>$limit)throw new InvalidArgumentException(t('Calendar feed exceeds the supported event limit.'));
        $masters=[];$overrides=[];
        foreach($blocks as $props){$map=[];foreach($props as $p){$map[$p[0]][]=$p;}
            $uid=$map['UID'][0][1] ?? '';if($uid==='')throw new InvalidArgumentException(t('Every event needs UID, title, start and end.'));
            if(isset($map['RECURRENCE-ID'])){
                $rid=$map['RECURRENCE-ID'][0];if(isset($rid[2]['RANGE'])&&$rid[2]['RANGE']!=='THISANDFUTURE')throw new InvalidArgumentException(t('Unsupported recurrence range.'));
                $key=self::identity(self::date($rid[1],$rid[2]));if(isset($overrides[$uid][$key]))throw new InvalidArgumentException(t('Duplicate calendar UIDs in this import.'));$overrides[$uid][$key]=$props;
            }else{if(isset($masters[$uid]))throw new InvalidArgumentException(t('Duplicate calendar UIDs in this import.'));$masters[$uid]=$props;}
        }
        $lines=['BEGIN:VCALENDAR','VERSION:2.0'];$total=0;
        foreach($masters as $uid=>$props){$map=[];foreach($props as $p){if(isset($map[$p[0]])&&!in_array($p[0],['RDATE','EXDATE'],true))throw new InvalidArgumentException(t('Duplicate event property.'));$map[$p[0]][]=$p;}
            $emitted=[];
            $recurring=isset($map['RRULE'])||isset($map['RDATE'])||isset($map['EXDATE'])||isset($overrides[$uid]);
            if(!$recurring){$instances=[['props'=>$props,'key'=>null]];}
            else{
                if(!isset($map['DTSTART']))throw new InvalidArgumentException(t('Every event needs UID, title, start and end.'));
                $start=self::date($map['DTSTART'][0][1],$map['DTSTART'][0][2]);
                $allDay=strlen($map['DTSTART'][0][1])===8;
                $end=isset($map['DTEND'])?self::date($map['DTEND'][0][1],$map['DTEND'][0][2]):($allDay?$start->modify('+1 day'):null);
                if(!$end||$end<=$start)throw new InvalidArgumentException(t('Event end must follow its start.'));
                $duration=$start->diff($end);$set=new \RRule\RSet();$set->addDate($start);
                foreach($map['RRULE'] ?? [] as $rule){
                    if(!preg_match('/(?:^|;)FREQ=(SECONDLY|MINUTELY|HOURLY|DAILY|WEEKLY|MONTHLY|YEARLY)(?:;|$)/',$rule[1]))throw new InvalidArgumentException(t('Choose a supported recurrence frequency.'));
                    $ruleText=$rule[1];
                    if(!preg_match('/(?:^|;)(?:COUNT|UNTIL)=/',$ruleText))$ruleText.=';UNTIL='.$upper->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
                    $rrule=new \RRule\RRule($ruleText,$start);$set->addRRule($rrule);
                }
                foreach(['RDATE'=>'addDate','EXDATE'=>'addExDate']as $kind=>$method)foreach($map[$kind] ?? [] as $p)foreach(explode(',',$p[1])as $value)$set->$method(self::date($value,$p[2]));
                $instances=[];$scanned=0;$seen=[];
                foreach($set as $date){if(++$scanned>20000)throw new InvalidArgumentException(t('The recurrence exceeds the import calculation limit.'));
                    if($date>$upper)break; $key=self::identity($date);$seen[$key]=true;
                    $replacement=$overrides[$uid][$key] ?? self::futureOverride($overrides[$uid] ?? [],$date,$duration,$allDay);
                    if($replacement!==null){$instances[]=['props'=>self::exception($props,$replacement,$date,$duration,$allDay),'key'=>$key];continue;}
                    if($date<$lower)continue;
                    $instance=$props;$instance=array_values(array_filter($instance,fn($p)=>!in_array($p[0],['DTSTART','DTEND'],true)));
                    $instance[]=self::dateProperty('DTSTART',$date,$allDay);$instance[]=self::dateProperty('DTEND',DateTimeImmutable::createFromInterface($date)->add($duration),$allDay);
                    $instances[]=['props'=>$instance,'key'=>$key];
                    if(count($instances)>$limit)throw new InvalidArgumentException(t('Calendar feed exceeds the supported event limit.'));
                }
                // An override can move an occurrence into the window from outside it.
                foreach($overrides[$uid] ?? []as $key=>$replacement)if(!isset($seen[$key]))$instances[]=['props'=>self::exception($props,$replacement,(new DateTimeImmutable('@'.$key))->setTimezone($start->getTimezone()),$duration,$allDay),'key'=>$key];
            }
            foreach($instances as $instance){$flat=[];foreach($instance['props']as [$name,$value,$params]){
                if(in_array($name,['RRULE','RDATE','EXDATE','RECURRENCE-ID'],true))continue;
                if($name==='UID'&&$instance['key']!==null)$value=(string)$instance['key']===self::identity($start)?$uid:'lifeos-series-'.hash('sha256',$uid.'|'.$instance['key']);
                $flat[$name]=[$value,$params];
            }
                if($recurring){$d=self::date($flat['DTSTART'][0],$flat['DTSTART'][1]);if($d<$lower||$d>$upper)continue;
                    $flat['X-LIFEOS-SERIES']=[hash('sha256',$uid),[]];$flat['X-LIFEOS-OCCURRENCE']=[gmdate('Y-m-d H:i:s',(int)$instance['key']),[]];$emitted[]=$flat['UID'][0];
                }
                if(++$total>$limit)throw new InvalidArgumentException(t('Calendar feed exceeds the supported event limit.'));
                $lines[]='BEGIN:VEVENT';foreach($flat as $name=>[$value,$params]){$key=$name;foreach($params as $k=>$v)$key.=';'.$k.'='.$v;$lines[]=$key.':'.$value;}$lines[]='END:VEVENT';
            }
            if($recurring){
                $fromUtc=$lower->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$untilUtc=$upper->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                foreach(Db::all("SELECT c.remote_id,c.occurrence_at,p.* FROM connectivity_events c JOIN planner_appointments p ON p.id=c.appointment_id WHERE c.provider='ics' AND c.series_uid=? AND c.occurrence_at>=? AND c.occurrence_at<=?",[hash('sha256',$uid),$fromUtc,$untilUtc])as $old){
                    if(in_array($old['remote_id'],$emitted,true)||$old['status']==='cancelled')continue;
                    if(++$total>$limit)throw new InvalidArgumentException(t('Calendar feed exceeds the supported event limit.'));
                    $lines[]='BEGIN:VEVENT';$lines[]='UID:'.$old['remote_id'];$lines[]='SUMMARY:'.str_replace(["\\","\r","\n",';',','],["\\\\",'',"\\n",'\\;','\\,'],$old['title']);
                    foreach(['LOCATION'=>'location','DESCRIPTION'=>'notes']as $name=>$field)$lines[]=$name.':'.str_replace(["\\","\r","\n",';',','],["\\\\",'',"\\n",'\\;','\\,'],(string)($old[$field] ?? ''));
                    foreach(['DTSTART'=>'starts_at','DTEND'=>'ends_at']as $name=>$field){$p=self::dateProperty($name,new DateTimeImmutable($old[$field],new DateTimeZone('Asia/Colombo')),(bool)$old['all_day']);$lines[]=$name.($old['all_day']?';VALUE=DATE':'').':'.$p[1];}
                    $lines[]='STATUS:CANCELLED';$lines[]='X-LIFEOS-SERIES:'.hash('sha256',$uid);$lines[]='X-LIFEOS-OCCURRENCE:'.$old['occurrence_at'];$lines[]='END:VEVENT';
                }
            }
        }
        if(array_diff(array_keys($overrides),array_keys($masters)))throw new InvalidArgumentException(t('Recurring exceptions need their series master.'));
        $lines[]='END:VCALENDAR';return implode("\r\n",$lines);
    }
    private static function dateProperty(string $name,DateTimeInterface $date,bool $allDay): array {
        return $allDay?[$name,$date->format('Ymd'),['VALUE'=>'DATE']]:[$name,DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),[]];
    }
    private static function futureOverride(array $overrides,DateTimeInterface $date,DateInterval $duration,bool $allDay): ?array {
        $chosen=null;$chosenKey=PHP_INT_MIN;
        foreach($overrides as $key=>$props){
            if((int)$key>(int)$date->format('U')||(int)$key<$chosenKey)continue;
            foreach($props as $p)if($p[0]==='RECURRENCE-ID'&&($p[2]['RANGE'] ?? '')==='THISANDFUTURE'){$chosen=$props;$chosenKey=(int)$key;}
        }
        if($chosen===null)return null;
        $map=[];foreach($chosen as $p)$map[$p[0]]=$p;
        $original=(new DateTimeImmutable('@'.$chosenKey))->setTimezone($date->getTimezone());
        $moved=isset($map['DTSTART'])?self::date($map['DTSTART'][1],$map['DTSTART'][2]):$original;
        $newStart=DateTimeImmutable::createFromInterface($date)->add($original->diff($moved->setTimezone($date->getTimezone())));
        $newDuration=isset($map['DTEND'])?$moved->diff(self::date($map['DTEND'][1],$map['DTEND'][2])):$duration;
        $map['DTSTART']=self::dateProperty('DTSTART',$newStart,$allDay);$map['DTEND']=self::dateProperty('DTEND',$newStart->add($newDuration),$allDay);
        return array_values($map);
    }
    private static function exception(array $master,array $replacement,DateTimeInterface $original,DateInterval $duration,bool $allDay): array {
        $map=[];foreach($master as $p)$map[$p[0]]=$p;foreach($replacement as $p)$map[$p[0]]=$p;
        $start=null;foreach($replacement as $p)if($p[0]==='DTSTART')$start=self::date($p[1],$p[2]);$start??=DateTimeImmutable::createFromInterface($original);
        $map['DTSTART']=self::dateProperty('DTSTART',$start,$allDay);
        if(!array_filter($replacement,fn($p)=>$p[0]==='DTEND'))$map['DTEND']=self::dateProperty('DTEND',$start->add($duration),$allDay);
        return array_values($map);
    }
}
