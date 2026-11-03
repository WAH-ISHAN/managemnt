<?php
declare(strict_types=1);
/** Reversible first-fit planner. Appointments and pinned blocks always win. */
final class PlanningService {
    public static function preferences(): array {
        return ['start'=>Db::setting('planning_start','09:00'),'end'=>Db::setting('planning_end','17:00'),'days'=>explode(',',Db::setting('planning_days','1,2,3,4,5')),'enabled'=>Db::setting('planning_enabled')==='1','reschedule'=>Db::setting('planning_reschedule')==='1','archive_days'=>(int)Db::setting('planning_archive_days','0')];
    }
    public static function configure(array $d): void {
        $start=PlannerService::validTime($d['start'] ?? ''); $end=PlannerService::validTime($d['end'] ?? '');
        if ($end<=$start) throw new InvalidArgumentException(t('Availability end must follow its start.'));
        $days=$d['days'] ?? []; if (!is_array($days) || !$days) throw new InvalidArgumentException(t('Choose available weekdays.'));
        $days=array_unique(array_map(fn($v)=>int_range($v,1,7),$days)); sort($days); $archive=int_range($d['archive_days'] ?? 0,0,3650);
        Db::tx(function () use ($d,$start,$end,$days,$archive) { foreach (['planning_start'=>$start,'planning_end'=>$end,'planning_days'=>implode(',',$days),'planning_enabled'=>isset($d['enabled'])?'1':'0','planning_reschedule'=>isset($d['reschedule'])?'1':'0','planning_archive_days'=>(string)$archive] as $key=>$value) Db::set($key,$value); });
    }
    public static function preview(string $date,bool $reschedule=false,?int $now=null,bool $onlyOverdue=false): array {
        valid_date($date); $p=self::preferences(); $now??=time();
        $eligibility=$onlyOverdue ? 'due<?' : '(due IS NULL OR due<=?)';
        $tasks=Db::all("SELECT * FROM tasks WHERE status='open' AND archived_at IS NULL AND ".$eligibility." ORDER BY CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END,due IS NULL,due,id LIMIT 200",[$date]);
        $busy=Db::all("SELECT starts_at,ends_at FROM planner_appointments WHERE status='scheduled' AND ends_at>? AND starts_at<?",[$date.' 00:00',(new DateTimeImmutable($date))->modify('+15 days')->format('Y-m-d').' 00:00']);
        $blocks=Db::all('SELECT * FROM planner_blocks WHERE ends_at>? ORDER BY starts_at',[$date.' 00:00']);
        foreach ($blocks as $b) $busy[]=$b;
        $changes=[]; $unscheduled=[];
        foreach ($tasks as $task) {
            if (array_filter($blocks,fn($b)=>(int)$b['task_id']===(int)$task['id'])) continue;
            $slot=null;
            // Daily mode stays within chosen day; overdue rescheduling searches 14 days.
            for ($offset=0;$offset<=($reschedule?14:0);$offset++) {
                $day=(new DateTimeImmutable($date))->modify('+'.$offset.' days'); $ymd=$day->format('Y-m-d');
                if (!in_array($day->format('N'),array_map('strval',$p['days']),true)) continue;
                if ($task['deadline'] && $ymd>$task['deadline']) break;
                $start=strtotime($ymd.' '.$p['start']); $end=strtotime($ymd.' '.$p['end']); $duration=(int)$task['estimate_minutes']*60;
                $start=max($start,(int)(ceil($now/300)*300));
                for ($candidate=$start;$candidate+$duration<=$end;$candidate+=300) {
                    $stop=$candidate+$duration; $conflict=false;
                    foreach ($busy as $b) if ($candidate<strtotime($b['ends_at']) && $stop>strtotime($b['starts_at'])) { $conflict=true; break; }
                    if (!$conflict) { $slot=['starts_at'=>date('Y-m-d H:i',$candidate),'ends_at'=>date('Y-m-d H:i',$stop)]; break 2; }
                }
            }
            if (!$slot) { $unscheduled[]=['id'=>$task['id'],'title'=>$task['title'],'reason'=>'No free time before the deadline in the selected availability.']; continue; }
            $busy[]=$slot;
            $changes[]=['task_id'=>(int)$task['id'],'title'=>$task['title'],'before_due'=>$task['due'],'after_due'=>$reschedule && $task['due']!==null && $task['due']<substr($slot['starts_at'],0,10)?substr($slot['starts_at'],0,10):$task['due'],'deadline'=>$task['deadline'],'estimate_minutes'=>$task['estimate_minutes'],'priority'=>$task['priority']]+$slot;
        }
        return ['date'=>$date,'reschedule'=>$reschedule,'changes'=>$changes,'unscheduled'=>$unscheduled,'fingerprint'=>self::fingerprint(),'availability'=>$p];
    }
    private static function fingerprint(): string {
        return hash('sha256',json_encode([Db::all('SELECT id,title,due,status,priority,estimate_minutes,deadline,archived_at FROM tasks ORDER BY id'),Db::all('SELECT * FROM planner_appointments ORDER BY id'),Db::all('SELECT * FROM planner_blocks ORDER BY id'),self::preferences()],JSON_THROW_ON_ERROR));
    }
    public static function apply(array $preview,?int $now=null): int {
        return Db::tx(fn()=>self::applyChanges($preview,$now ?? time()));
    }
    private static function applyChanges(array $preview,int $now): int {
            if (!isset($preview['fingerprint']) || !hash_equals(self::fingerprint(),$preview['fingerprint'])) throw new InvalidArgumentException(t('Your schedule changed. Preview it again.'));
            if (empty($preview['changes'])) throw new InvalidArgumentException(t('There are no blocks to apply.'));
            $preferences=self::preferences(); $busy=Db::all("SELECT starts_at,ends_at FROM planner_appointments WHERE status='scheduled'");
            foreach (Db::all('SELECT starts_at,ends_at FROM planner_blocks') as $block) $busy[]=$block;
            $taskIds=[];
            foreach ($preview['changes'] as $c) {
                $task=PlannerService::task((int)$c['task_id']);
                $start=PlannerService::validDateTime($c['starts_at']); $end=PlannerService::validDateTime($c['ends_at']); $day=substr($start,0,10);
                if ($task['status']!=='open' || $task['archived_at']!==null || isset($taskIds[$task['id']]) || $task['due']!==$c['before_due']) throw new InvalidArgumentException(t('A planned task changed. Preview again.'));
                if (strtotime($start)<$now || strtotime($end)-strtotime($start)!==(int)$task['estimate_minutes']*60 || substr($end,0,10)!==$day || substr($start,11)<$preferences['start'] || substr($end,11)>$preferences['end'] || !in_array(date('N',strtotime($start)),array_map('strval',$preferences['days']),true)) throw new InvalidArgumentException(t('The preview no longer fits a future availability window. Preview again.'));
                $after=PlannerService::dateInput($c['after_due'],true);
                if ($task['deadline'] && ($day>$task['deadline'] || ($after && $after>$task['deadline']))) throw new InvalidArgumentException(t('A planned block exceeds its deadline.'));
                foreach ($busy as $b) if ($start<$b['ends_at'] && $end>$b['starts_at']) throw new InvalidArgumentException(t('A planned block overlaps protected time. Preview again.'));
                $busy[]=['starts_at'=>$start,'ends_at'=>$end]; $taskIds[$task['id']]=true;
            }
            // The HTTP controller reads this preview only from the authenticated session.
            $id=Db::run('INSERT INTO planner_batches(kind,changes) VALUES (?,?)',[$preview['reschedule']?'reschedule':'time-block',json_encode($preview['changes'],JSON_THROW_ON_ERROR)]);
            foreach ($preview['changes'] as $c) {
                Db::run('INSERT INTO planner_blocks(task_id,starts_at,ends_at,batch_id) VALUES (?,?,?,?)',[$c['task_id'],$c['starts_at'],$c['ends_at'],$id]);
                Db::run('UPDATE tasks SET due=? WHERE id=?',[$c['after_due'],$c['task_id']]);
            }
            return $id;
    }
    public static function undo(int $id): void {
        Db::tx(function () use ($id) {
            $batch=Db::one('SELECT * FROM planner_batches WHERE id=? AND undone=0',[$id]); if (!$batch) throw new InvalidArgumentException(t('This plan cannot be undone.'));
            $changes=json_decode($batch['changes'],true,512,JSON_THROW_ON_ERROR);
            foreach ($changes as $c) {
                $task=Db::one('SELECT due,status,archived_at FROM tasks WHERE id=?',[$c['task_id']]); $block=Db::one('SELECT * FROM planner_blocks WHERE batch_id=? AND task_id=?',[$id,$c['task_id']]);
                if (!$task || $task['status']!=='open' || $task['archived_at']!==null || !$block || $block['pinned'] || $task['due']!==$c['after_due'] || $block['starts_at']!==$c['starts_at'] || $block['ends_at']!==$c['ends_at']) throw new InvalidArgumentException(t('A planned task or block changed. Unpin or resolve it before undo.'));
            }
            foreach ($changes as $c) Db::run('UPDATE tasks SET due=? WHERE id=?',[$c['before_due'],$c['task_id']]);
            Db::run('DELETE FROM planner_blocks WHERE batch_id=?',[$id]); Db::run('UPDATE planner_batches SET undone=1 WHERE id=?',[$id]);
        });
    }
    public static function archive(int $days,?string $today=null): int {
        int_range($days,1,3650); $date=valid_date($today ?? date('Y-m-d'));
        $cutoff=(new DateTimeImmutable($date))->modify('-'.$days.' days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $rows=Db::all("SELECT id FROM tasks WHERE status='done' AND archived_at IS NULL AND completed_at IS NOT NULL AND completed_at<?",[$cutoff]);
        Db::run("UPDATE tasks SET archived_at=CURRENT_TIMESTAMP WHERE status='done' AND archived_at IS NULL AND completed_at IS NOT NULL AND completed_at<?",[$cutoff]); return count($rows);
    }
    public static function tick(?string $today=null,?int $now=null): array {
        $now??=time(); $today=valid_date($today ?? date('Y-m-d',$now)); $p=self::preferences(); $empty=['blocks'=>0,'archived'=>0];
        if (!$p['enabled'] && !$p['reschedule'] && !$p['archive_days']) return $empty;
        if (($p['enabled'] || $p['reschedule']) && date('H:i',$now)<$p['start']) return $empty;
        return Db::tx(function () use ($today,$now,$p,$empty): array {
            // Claim the slot before reading records, acquiring SQLite's writer lock.
            // Effects and the slot commit together; failures leave the day retryable.
            Db::run('INSERT OR IGNORE INTO planner_job_runs(slot,result) VALUES (?,?)',[$today,'{}']);
            if (!(int)Db::one('SELECT changes() n')['n']) return $empty;
            $result=$empty;
            if ($p['enabled'] || $p['reschedule']) {
                $preview=self::preview($today,$p['reschedule'],$now,!$p['enabled']);
                if ($preview['changes']) { self::applyChanges($preview,$now); $result['blocks']=count($preview['changes']); }
            }
            if ($p['archive_days']>0) $result['archived']=self::archive($p['archive_days'],$today);
            Db::run('UPDATE planner_job_runs SET result=? WHERE slot=?',[json_encode($result,JSON_THROW_ON_ERROR),$today]);
            return $result;
        });
    }
}
