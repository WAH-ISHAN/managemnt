<?php
declare(strict_types=1);
/** Bounded RFC5545 single-event exchange. Unsupported recurrence is rejected, never silently flattened. */
final class ConnectivityIcs {
    private static function unescape(string $s): string { return preg_replace_callback('/\\\\([nN,;\\\\])/',fn($m)=>in_array($m[1],['n','N'],true) ? "\n" : $m[1],$s); }
    private static function escape(string $s): string { return str_replace(["\\","\r\n","\r","\n",';',','],["\\\\","\\n","\\n","\\n","\\;","\\,"],$s); }
    private static function date(string $value,array $params): string {
        if (($params['VALUE'] ?? '')==='DATE' || preg_match('/^\d{8}$/D',$value)) {
            $date=DateTimeImmutable::createFromFormat('!Ymd',$value);
            if (!$date || $date->format('Ymd')!==$value) throw new InvalidArgumentException(t('Calendar contains an invalid date.'));
            return $date->format('Y-m-d').' 00:00';
        }
        $zone=$params['TZID'] ?? 'Asia/Colombo';
        try { $tz=new DateTimeZone($zone); } catch (Throwable) { throw new InvalidArgumentException(t('Unknown calendar timezone.')); }
        $format=str_ends_with($value,'Z') ? '!Ymd\THis\Z' : (strlen($value)===13 ? '!Ymd\THi' : '!Ymd\THis');
        $d=DateTimeImmutable::createFromFormat($format,$value,str_ends_with($value,'Z') ? new DateTimeZone('UTC') : $tz);
        $errors=DateTimeImmutable::getLastErrors();
        if (!$d || ($errors && ($errors['warning_count'] || $errors['error_count']))) throw new InvalidArgumentException(t('Calendar contains an invalid date.'));
        return $d->setTimezone(new DateTimeZone('Asia/Colombo'))->format('Y-m-d H:i');
    }
    public static function preview(string $ics,?string $from=null,?string $until=null): array {
        if (strlen($ics)>2*1024*1024 || str_contains($ics,"\0")) throw new InvalidArgumentException(t('Choose a calendar smaller than 2 MiB.'));
        if (!str_contains($ics,'BEGIN:VCALENDAR') || !str_contains($ics,'END:VCALENDAR')) throw new InvalidArgumentException(t('Choose a valid ICS calendar.'));
        $ics=ConnectivityRecurrence::expand($ics,$from,$until);
        $ics=preg_replace('/\r?\n[ \t]/','',$ics);
        if (!str_contains($ics,'BEGIN:VCALENDAR') || !str_contains($ics,'END:VCALENDAR')) throw new InvalidArgumentException(t('Choose a valid ICS calendar.'));
        $events=[]; $event=null;
        foreach (preg_split('/\r\n|\n|\r/',$ics) as $line) {
            if ($line==='BEGIN:VEVENT') { if ($event!==null) throw new InvalidArgumentException(t('Nested calendar events are unsupported.')); $event=[]; continue; }
            if ($line==='END:VEVENT') {
                if (!$event || !isset($event['UID'],$event['DTSTART'],$event['SUMMARY'])) throw new InvalidArgumentException(t('Every event needs UID, title, start and end.'));
                $allDay=($event['DTSTART'][1]['VALUE'] ?? '')==='DATE' || preg_match('/^\d{8}$/D',$event['DTSTART'][0]);
                if (!isset($event['DTEND']) && $allDay) $event['DTEND']=[(new DateTimeImmutable(self::date(...$event['DTSTART'])))->modify('+1 day')->format('Ymd'),['VALUE'=>'DATE']];
                if (!isset($event['DTEND'])) throw new InvalidArgumentException(t('Every event needs UID, title, start and end.'));
                $endAllDay=($event['DTEND'][1]['VALUE'] ?? '')==='DATE' || preg_match('/^\d{8}$/D',$event['DTEND'][0]);
                if ((bool)$allDay!==(bool)$endAllDay) throw new InvalidArgumentException(t('Calendar start and end must use the same date format.'));
                if (count($events)>=500) throw new InvalidArgumentException(t('Import at most 500 events at a time.'));
                $row=['uid'=>PlannerService::title($event['UID'][0],500),'title'=>PlannerService::title(self::unescape($event['SUMMARY'][0])),'starts_at'=>self::date(...$event['DTSTART']),'ends_at'=>self::date(...$event['DTEND']),'location'=>mb_substr(self::unescape($event['LOCATION'][0] ?? ''),0,200),'notes'=>mb_substr(self::unescape($event['DESCRIPTION'][0] ?? ''),0,2000),'status'=>($event['STATUS'][0] ?? '')==='CANCELLED' ? 'cancelled' : 'scheduled','all_day'=>$allDay ? 1 : 0];
                if ($row['ends_at']<=$row['starts_at']) throw new InvalidArgumentException(t('Event end must follow its start.'));
                $row['series_uid']=$event['X-LIFEOS-SERIES'][0] ?? null;
                $row['occurrence_at']=$event['X-LIFEOS-OCCURRENCE'][0] ?? null;
                if($row['series_uid']!==null && (!preg_match('/^[a-f0-9]{64}$/D',$row['series_uid']) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',(string)$row['occurrence_at'])))throw new InvalidArgumentException(t('Invalid recurring instance metadata.'));
                $events[]=$row; $event=null; continue;
            }
            if ($event===null || !str_contains($line,':')) continue;
            [$key,$value]=explode(':',$line,2); $parts=explode(';',$key); $name=strtoupper(array_shift($parts));
            if (in_array($name,['RRULE','RDATE','EXDATE','RECURRENCE-ID'],true)) throw new InvalidArgumentException(t('Expand recurring events into individual instances before importing.'));
            $params=[]; foreach ($parts as $part) { $pair=explode('=',$part,2); if (count($pair)===2) $params[strtoupper($pair[0])]=trim($pair[1],'"'); }
            if (isset($event[$name])) throw new InvalidArgumentException(t('Duplicate event property.'));
            $event[$name]=[$value,$params];
        }
        if ($event!==null) throw new InvalidArgumentException(t('Incomplete calendar event.'));
        if (count(array_unique(array_column($events,'uid')))!==count($events)) throw new InvalidArgumentException(t('Duplicate calendar UIDs in this import.'));
        return $events;
    }
    public static function reviewHash(string $ics,?string $from=null,?string $until=null): string {
        $rows=self::preview($ics,$from,$until);$current=[];
        foreach($rows as $row)$current[]=Db::one("SELECT c.appointment_id,p.* FROM connectivity_events c JOIN planner_appointments p ON p.id=c.appointment_id WHERE c.provider='ics' AND c.remote_id=?",[$row['uid']]);
        return hash('sha256',json_encode([$rows,$current],JSON_THROW_ON_ERROR));
    }
    public static function import(string $ics,?string $from=null,?string $until=null,?string $expectedHash=null): int {
        $events=self::preview($ics,$from,$until);
        return Db::tx(function () use ($events,$ics,$from,$until,$expectedHash) {
            // Claim SQLite's writer before checking the reviewed local state.
            Db::run("UPDATE connectivity_events SET local_hash=local_hash WHERE provider='ics'");
            if($expectedHash!==null&&!hash_equals($expectedHash,self::reviewHash($ics,$from,$until)))throw new InvalidArgumentException(t('The calendar changed after review. Preview it again.'));
            $events=self::preview($ics,$from,$until);
            $count=0;
            foreach ($events as $row) {
                $link=Db::one("SELECT * FROM connectivity_events WHERE provider='ics' AND remote_id=?",[$row['uid']]);
                // Imported calendars update their own linked records only.
                $id=PlannerService::saveAppointment($link['appointment_id'] ?? null,$row);
                $hash=hash('sha256',json_encode($row));
                Db::run("INSERT INTO connectivity_events(provider,remote_id,appointment_id,local_hash) VALUES ('ics',?,?,?) ON CONFLICT(provider,remote_id) DO UPDATE SET appointment_id=excluded.appointment_id,local_hash=excluded.local_hash",[$row['uid'],$id,$hash]);
                Db::run("UPDATE connectivity_events SET series_uid=?,occurrence_at=? WHERE provider='ics' AND remote_id=?",[$row['series_uid'],$row['occurrence_at'],$row['uid']]);
                $count++;
            }
            return $count;
        });
    }
    private static function fold(string $line): string {
        $parts=[]; $part='';
        foreach (preg_split('//u',$line,-1,PREG_SPLIT_NO_EMPTY) as $char) { if (strlen($part.$char)>73) { $parts[]=$part; $part=' '.$char; } else $part.=$char; }
        $parts[]=$part; return implode("\r\n",$parts);
    }
    public static function export(): string {
        $lines=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//LifeOS//Local calendar//EN','CALSCALE:GREGORIAN'];
        foreach (Db::all('SELECT * FROM planner_appointments ORDER BY starts_at,id LIMIT 5000') as $a) {
            $id=hash('sha256',Db::setting('connection_calendar_identity','lifeos').':'.$a['id']);
            $utc=fn($v)=>(new DateTimeImmutable($v,new DateTimeZone('Asia/Colombo')))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
            $start=$a['all_day'] ? 'DTSTART;VALUE=DATE:'.str_replace('-','',substr($a['starts_at'],0,10)) : 'DTSTART:'.$utc($a['starts_at']);
            $end=$a['all_day'] ? 'DTEND;VALUE=DATE:'.str_replace('-','',substr($a['ends_at'],0,10)) : 'DTEND:'.$utc($a['ends_at']);
            array_push($lines,'BEGIN:VEVENT','UID:'.$id.'@lifeos.local','DTSTAMP:'.gmdate('Ymd\THis\Z'),$start,$end,'SUMMARY:'.self::escape($a['title']),'LOCATION:'.self::escape($a['location'] ?? ''),'DESCRIPTION:'.self::escape($a['notes'] ?? ''),'STATUS:'.($a['status']==='cancelled' ? 'CANCELLED' : 'CONFIRMED'),'END:VEVENT');
        }
        $lines[]='END:VCALENDAR'; return implode("\r\n",array_map(self::fold(...),$lines))."\r\n";
    }
}
