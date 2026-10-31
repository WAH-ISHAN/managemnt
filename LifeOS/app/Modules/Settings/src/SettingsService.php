<?php
declare(strict_types=1);
final class SettingsService {
    public const EXPORTABLE=['accounts','income','allocations','allocation_rules','expenses','expense_categories','assets','asset_transactions','asset_metrics','goals','uni_modules','assignments','exams','timetable','tasks','lessons','weekly_reviews','salary_plans','financial_obligations','financial_payments','financial_recurrences','financial_occurrences','planner_habits','planner_habit_checks','planner_routines','planner_routine_checks','planner_appointments','personal_goals','personal_goal_steps','career_skills','career_courses','career_applications','focus_sessions','monthly_life_reviews','documents','freedom_time','life_expiries','life_subscriptions','life_loans','life_loan_payments','business_projects','business_entries','website_profiles','website_operations'];
    public static function tables(): array {
        $actual=array_column(Db::all("SELECT name FROM sqlite_master WHERE type='table'"),'name');
        return array_values(array_intersect(self::EXPORTABLE,$actual));
    }
    public static function csv(string $table): string {
        if (!in_array($table,self::tables(),true)) { http_response_code(404); return 'Unknown export table'; }
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$table.'-'.date('Y-m-d').'.csv"');
        $out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,array_column(Db::all('PRAGMA table_info('.$table.')'),'name'),',','"','');
        foreach (Db::all('SELECT * FROM '.$table) as $row) {
            $safe=array_map(function ($v) { if (is_string($v) && !is_numeric($v) && preg_match('/^[=+@\-\t\r]/',$v)) return "'".$v; return $v; },$row);
            fputcsv($out,$safe,',','"','');
        }
        fclose($out); return '';
    }
}
