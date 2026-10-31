<?php
declare(strict_types=1);
final class FreedomService {
    public const CATEGORIES=['rest'=>'Rest','relationships'=>'Relationships','health'=>'Health','experiences'=>'Experiences','learning'=>'Learning'];
    public static function saveSettings(array $data): void {
        $hours=$data['weekly_work_hours'] ?? '';
        if ($hours==='' || $hours===null) { Db::set('freedom_weekly_work_hours',''); return; }
        $value=cents($hours,true);
        if ($value>16800) throw new InvalidArgumentException('Enter weekly work hours from 0 to 168, or leave the field empty.');
        Db::set('freedom_weekly_work_hours',number_format($value/100,2,'.',''));
    }
    public static function record(int $id): array {
        $row=Db::one('SELECT * FROM freedom_time WHERE id=?',[$id]);
        if (!$row) throw new InvalidArgumentException('Personal time entry not found.'); return $row;
    }
    public static function saveTime(?int $id,array $data): int {
        if ($id) self::record($id);
        $date=$data['date'] ?? ''; if (!is_string($date)) throw new InvalidArgumentException('Choose a valid date.'); valid_date($date);
        if ($date>date('Y-m-d')) throw new InvalidArgumentException('Log personal time on today or an earlier date.');
        $category=$data['category'] ?? ''; if (!is_string($category) || !isset(self::CATEGORIES[$category])) throw new InvalidArgumentException('Choose an existing personal time category.');
        $minutes=int_range($data['minutes'] ?? '',1,1440); $note=$data['note'] ?? '';
        if (!is_string($note) || mb_strlen($note)>2000 || str_contains($note,"\0")) throw new InvalidArgumentException('Use a note of up to 2,000 characters.');
        return Db::tx(function () use ($id,$date,$category,$minutes,$note) {
            $logged=(int)Db::one('SELECT COALESCE(SUM(minutes),0) n FROM freedom_time WHERE date=? AND id<>?',[$date,$id ?? 0])['n'];
            if ($logged+$minutes>1440) throw new InvalidArgumentException('Personal time entries for one day cannot exceed 24 hours.');
            if ($id) { Db::run('UPDATE freedom_time SET date=?,category=?,minutes=?,note=? WHERE id=?',[$date,$category,$minutes,trim($note),$id]); return $id; }
            return Db::run('INSERT INTO freedom_time(date,category,minutes,note) VALUES (?,?,?,?)',[$date,$category,$minutes,trim($note)]);
        });
    }
    public static function deleteTime(int $id): void { self::record($id); Db::run('DELETE FROM freedom_time WHERE id=?',[$id]); }
    public static function overview(?string $today=null): array {
        $today=valid_date($today ?? date('Y-m-d')); $start=(new DateTimeImmutable($today))->modify('monday this week')->format('Y-m-d'); $end=(new DateTimeImmutable($start))->modify('+7 days')->format('Y-m-d');
        $tables=array_column(Db::all("SELECT name FROM sqlite_master WHERE type='table'"),'name');
        $has=fn(string $table)=>in_array($table,$tables,true);
        $plan=class_exists('FinancePlanningService') && $has('salary_plans') ? FinancePlanningService::plan(substr($today,0,7)) : null;
        $emergency=null;
        if ($plan && $plan['emergency_account_id'] && $has('accounts')) {
            $account=Db::one("SELECT balance FROM accounts WHERE id=? AND kind='savings'",[$plan['emergency_account_id']]);
            if ($account) $emergency=(float)$account['balance'];
        }
        $essentials=$plan ? (float)$plan['essentials'] : null;
        $hours=Db::setting('freedom_weekly_work_hours');
        $totals=array_fill_keys(array_keys(self::CATEGORIES),0);
        foreach (Db::all('SELECT category,SUM(minutes) minutes FROM freedom_time WHERE date>=? AND date<? GROUP BY category',[$start,$end]) as $row) $totals[$row['category']]=(int)$row['minutes'];
        $goals=class_exists('GrowthService') && $has('personal_goals') && $has('personal_goal_steps') ? array_values(array_filter(GrowthService::goals(),fn($goal)=>$goal['status']==='active')) : [];
        return ['today'=>$today,'week_start'=>$start,'week_end'=>$end,'weekly_work_hours'=>$hours==='' ? null : (float)$hours,'plan'=>$plan,'monthly_essentials'=>$essentials,'emergency_savings'=>$emergency,'runway_months'=>$essentials!==null && $essentials>0 && $emergency!==null ? round(max(0,$emergency)/$essentials,2) : null,'outstanding_debt'=>class_exists('FinancePlanningService') && $has('financial_obligations') && Db::one("SELECT 1 FROM financial_obligations WHERE kind='loan' LIMIT 1") ? FinancePlanningService::outstandingDebt() : null,'goals'=>array_slice($goals,0,6),'appointments'=>class_exists('PlannerService') && $has('planner_appointments') ? PlannerService::upcoming(7) : [],'time_totals'=>$totals,'personal_minutes'=>array_sum($totals),'time_entries'=>Db::all('SELECT * FROM freedom_time WHERE date>=? AND date<? ORDER BY date DESC,id DESC',[$start,$end]),'recent_time_entries'=>Db::all('SELECT * FROM freedom_time ORDER BY date DESC,id DESC LIMIT 30')];
    }
}
