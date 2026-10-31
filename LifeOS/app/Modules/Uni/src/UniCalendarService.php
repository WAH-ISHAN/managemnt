<?php
declare(strict_types=1);

/** A complete, authenticated NIBM snapshot. University credentials never enter PHP. */
final class UniCalendarService {
    private const ACTIVE=['APPROVED','SCHEDULED','RESCHEDULED_APPROVED'];
    private const INACTIVE=['CANCELLED','RESCHEDULED_CANCELLED','RESCHEDULED','PENDING_APPROVAL','PENDING_CREATION','REJECTED','RESCHEDULED_PENDING_APPROVAL','RESCHEDULED_REJECTED'];
    public static function config(): array {
        return ['enabled'=>Db::setting('uni_calendar_enabled')==='1','batchId'=>Db::setting('uni_calendar_batch'),
            'portalUrl'=>Db::setting('uni_calendar_url'),'lastSync'=>Db::setting('uni_calendar_last_sync'),'lastError'=>Db::setting('uni_calendar_error')];
    }
    private static function uuid(mixed $value): string {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$value)) throw new InvalidArgumentException('Choose a valid NIBM batch or lecture ID.');
        return strtolower($value);
    }
    private static function text(mixed $value,int $max,bool $required=false): string {
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0") || mb_strlen($value)>$max || ($required && trim($value)==='')) throw new InvalidArgumentException('The calendar contains invalid or oversized text.');
        return trim($value);
    }
    public static function configure(string $portalUrl): array {
        $url=parse_url(trim($portalUrl));
        if (!$url || strlen($portalUrl)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',trim($portalUrl)) || ($url['scheme'] ?? '')!=='https' || strtolower($url['host'] ?? '')!=='aca.mynibm.com' || ($url['path'] ?? '')!=='/dashboard' || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || (isset($url['port']) && $url['port']!==443)) throw new InvalidArgumentException('Paste your HTTPS NIBM dashboard link, including its selected batch.');
        $batchKeys=0;
        foreach (explode('&',$url['query'] ?? '') as $pair) if (urldecode(explode('=',$pair,2)[0])==='batch') $batchKeys++;
        if ($batchKeys!==1) throw new InvalidArgumentException('Choose exactly one batch in your NIBM calendar link.');
        parse_str($url['query'] ?? '',$query); $batch=self::uuid($query['batch'] ?? '');
        $canonical='https://aca.mynibm.com/dashboard?view=month&batch='.$batch;
        Db::tx(function()use($batch,$canonical){
            $old=Db::setting('uni_calendar_batch');
            if ($old!=='' && $old!==$batch) {
                Db::run("UPDATE planner_appointments SET status='cancelled' WHERE id IN (SELECT appointment_id FROM uni_calendar_events WHERE batch_id=?)",[$old]);
                Db::set('uni_calendar_last_sync','');
            }
            Db::set('uni_calendar_batch',$batch); Db::set('uni_calendar_url',$canonical); Db::set('uni_calendar_enabled','1'); Db::set('uni_calendar_error','');
        });
        return self::config();
    }
    public static function pause(): void { Db::set('uni_calendar_enabled','0'); }
    public static function error(string $message): void {
        // Fixed messages only: never save raw API errors, tokens, or response bodies.
        $allowed=['Sign in to NIBM to continue syncing.','NIBM could not be reached. Your saved lectures are unchanged.','NIBM returned an unsupported calendar response. Your saved lectures are unchanged.'];
        if (!in_array($message,$allowed,true)) throw new InvalidArgumentException('Choose a valid calendar status.');
        Db::set('uni_calendar_error',$message);
    }
    private static function instant(mixed $value): string {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D',$value)) throw new InvalidArgumentException('The calendar must include valid dates, times and time zones.');
        try { $date=new DateTimeImmutable($value); $errors=DateTimeImmutable::getLastErrors(); }
        catch (Exception) { throw new InvalidArgumentException('The calendar contains an invalid date.'); }
        if (($errors!==false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y')<'2000' || $date->format('Y')>'2100') throw new InvalidArgumentException('The calendar contains an invalid date.');
        return $date->setTimezone(new DateTimeZone('Asia/Colombo'))->format('Y-m-d H:i');
    }
    public static function import(string $batchId,string $windowStart,string $windowEnd,array $lectures): array {
        $batchId=self::uuid($batchId); $config=self::config();
        if (!$config['enabled'] || $batchId!==$config['batchId']) throw new InvalidArgumentException('Connect this NIBM batch before syncing it.');
        valid_date($windowStart); valid_date($windowEnd);
        $from=new DateTimeImmutable($windowStart); $until=new DateTimeImmutable($windowEnd);
        if ($until<$from || (int)$from->diff($until)->days>399) throw new InvalidArgumentException('Sync a calendar window of up to 400 days.');
        if (!array_is_list($lectures) || count($lectures)>2000) throw new InvalidArgumentException('The NIBM calendar is too large or has an unsupported format.');
        $prepared=[];
        foreach ($lectures as $lecture) {
            if (!is_array($lecture)) throw new InvalidArgumentException('The calendar contains an invalid lecture.');
            $id=self::uuid($lecture['lectureId'] ?? null);
            if (isset($prepared[$id])) throw new InvalidArgumentException('The calendar contains repeated lecture IDs.');
            if (isset($lecture['batchId']) && self::uuid($lecture['batchId'])!==$batchId) throw new InvalidArgumentException('The calendar contains a lecture from another batch.');
            $start=self::instant($lecture['startAt'] ?? null); $end=self::instant($lecture['endAt'] ?? null);
            if ($end<=$start || substr($start,0,10)<$windowStart || substr($start,0,10)>$windowEnd) throw new InvalidArgumentException('A lecture is outside the requested calendar window or has invalid start/end times.');
            $status=self::text($lecture['status'] ?? null,50,true);
            if (!in_array($status,[...self::ACTIVE,...self::INACTIVE],true)) throw new InvalidArgumentException('NIBM returned an unsupported lecture status. Your saved lectures are unchanged.');
            $module=self::text($lecture['moduleName'] ?? '',150); $type=self::text($lecture['lectureType'] ?? '',50);
            $title=$module!=='' ? $module : ($type!=='' ? str_replace('_',' ',$type) : 'NIBM lecture');
            $location=self::text($lecture['facilityName'] ?? '',200);
            $notes='NIBM calendar'.($type!=='' ? ' · '.str_replace('_',' ',$type) : '');
            foreach (['internalLecturerName','visitingLecturerName','demonstratorName','tutorName','instructorName','technicalAssistantName'] as $key) {
                $name=self::text($lecture[$key] ?? '',150); if ($name!=='') $notes.="\n".$name;
            }
            $detailsHash=hash('sha256',json_encode([$title,$start,$end,$location,$status,$notes],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            $prepared[$id]=compact('id','start','end','status','title','location','notes','detailsHash');
        }
        return Db::tx(function()use($batchId,$windowStart,$windowEnd,$prepared){
            $counts=['created'=>0,'updated'=>0,'cancelled'=>0,'skipped'=>0]; $now=date('Y-m-d H:i:s'); $changes=[];
            foreach ($prepared as $item) {
                $old=Db::one('SELECT * FROM uni_calendar_events WHERE batch_id=? AND remote_id=?',[$batchId,$item['id']]);
                $active=in_array($item['status'],self::ACTIVE,true);
                if (!$old && !$active) { $counts['skipped']++; continue; }
                $local=($old['appointment_id'] ?? null) ? Db::one('SELECT id,status FROM planner_appointments WHERE id=?',[$old['appointment_id']]) : null;
                $appointment=$local['id'] ?? null;
                $previousActive=$old && in_array($old['remote_status'],self::ACTIVE,true);
                if ($active) {
                    $values=['NIBM · '.$item['title'],$item['start'],$item['end'],$item['location'],$item['notes']];
                    $missing=$appointment===null;
                    if ($missing) $appointment=Db::run("INSERT INTO planner_appointments(title,starts_at,ends_at,location,notes,reminder_minutes,status) VALUES (?,?,?,?,?,15,'scheduled')",$values);
                    // A batch switch cancels local reminders without changing the remote snapshot.
                    // Restore a confirmed lecture when that batch is selected again.
                    $changed=$old && (($old['details_hash'] ?? '')!==$item['detailsHash'] || !$previousActive || ($local && $local['status']!=='scheduled'));
                    if ($appointment && !$missing && $changed) Db::run("UPDATE planner_appointments SET title=?,starts_at=?,ends_at=?,location=?,notes=?,status='scheduled' WHERE id=?",[...$values,$appointment]);
                    if (!$old || $missing || !$previousActive) { $counts['created']++; $changes[]='Added: '.$item['title'].' · '.$item['start']; }
                    elseif ($changed) { $counts['updated']++; $changes[]='Changed: '.$item['title'].' · '.$item['start']; }
                    else $counts['skipped']++;
                } else {
                    if ($appointment && $previousActive) { Db::run("UPDATE planner_appointments SET status='cancelled' WHERE id=?",[$appointment]); $counts['cancelled']++; $changes[]='Cancelled: '.$item['title']; }
                    else $counts['skipped']++;
                }
                Db::run('INSERT INTO uni_calendar_events(batch_id,remote_id,appointment_id,title,starts_at,ends_at,location,remote_status,synced_at,details_hash) VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT(batch_id,remote_id) DO UPDATE SET appointment_id=excluded.appointment_id,title=excluded.title,starts_at=excluded.starts_at,ends_at=excluded.ends_at,location=excluded.location,remote_status=excluded.remote_status,synced_at=excluded.synced_at,details_hash=excluded.details_hash',[$batchId,$item['id'],$appointment,$item['title'],$item['start'],$item['end'],$item['location'],$item['status'],$now,$item['detailsHash']]);
            }
            // Only absence from this complete, successfully validated window cancels a saved event.
            foreach (Db::all('SELECT * FROM uni_calendar_events WHERE batch_id=? AND starts_at>=? AND starts_at<?',[$batchId,$windowStart.' 00:00',(new DateTimeImmutable($windowEnd))->modify('+1 day')->format('Y-m-d').' 00:00']) as $old) {
                if (isset($prepared[$old['remote_id']]) || $old['remote_status']==='MISSING') continue;
                if ($old['appointment_id'] && in_array($old['remote_status'],self::ACTIVE,true)) { Db::run("UPDATE planner_appointments SET status='cancelled' WHERE id=?",[$old['appointment_id']]); $counts['cancelled']++; $changes[]='Cancelled: '.$old['title']; }
                Db::run("UPDATE uni_calendar_events SET remote_status='MISSING',synced_at=? WHERE id=?",[$now,$old['id']]);
            }
            if ($changes) {
                $prefix=[]; foreach (['created'=>'new','updated'=>'changed','cancelled'=>'cancelled'] as $key=>$label) if ($counts[$key]) $prefix[]=$counts[$key].' '.$label;
                $body=implode(' · ',$prefix).'. '.implode('  ',$changes);
                Db::run('INSERT INTO uni_calendar_notifications(batch_id,title,body,created_at) VALUES (?,?,?,?)',[$batchId,'Lecture calendar updated',mb_substr($body,0,1200),(new DateTimeImmutable('now',new DateTimeZone('Asia/Colombo')))->format('Y-m-d H:i:s')]);
            }
            Db::set('uni_calendar_last_sync',$now); Db::set('uni_calendar_error','');
            return $counts;
        });
    }
    public static function events(string $month): array {
        valid_month($month); $start=$month.'-01'; $end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        return Db::all('SELECT e.*,p.status appointment_status FROM uni_calendar_events e LEFT JOIN planner_appointments p ON p.id=e.appointment_id WHERE e.batch_id=? AND e.starts_at>=? AND e.starts_at<? ORDER BY e.starts_at,e.id LIMIT 500',[Db::setting('uni_calendar_batch'),$start.' 00:00',$end.' 00:00']);
    }
}
