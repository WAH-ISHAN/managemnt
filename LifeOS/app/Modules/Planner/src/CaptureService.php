<?php
declare(strict_types=1);
final class CaptureService {
    /** Deterministic local grammar; no network and no invented dates for unknown wording. */
    public static function parse(string $raw,?string $today=null): array {
        $raw=PlannerService::title($raw,2000); $today=valid_date($today ?? date('Y-m-d'));
        $title=$raw; $due=null; $time=null; $priority='normal'; $minutes=30;
        $relative=['today'=>0,'tomorrow'=>1,'අද'=>0,'හෙට'=>1,'இன்று'=>0,'நாளை'=>1];
        $dateTokens=0; foreach (array_keys($relative) as $word) $dateTokens+=preg_match_all('/(?<![\pL\pN])'.preg_quote($word,'/').'(?![\pL\pN])/ui',$title);
        $dateTokens+=preg_match_all('/(?<!\d)(\d{4}-\d{2}-\d{2})(?!\d)/u',$title);
        if ($dateTokens>1) throw new InvalidArgumentException(t('Use only one due date.'));
        foreach ($relative as $word=>$offset) if (preg_match('/(?<![\pL\pN])'.preg_quote($word,'/').'(?![\pL\pN])/ui',$title,$m)) { $due=(new DateTimeImmutable($today))->modify('+'.$offset.' days')->format('Y-m-d'); $title=str_replace($m[0],' ',$title); break; }
        if (preg_match('/(?<!\d)(\d{4}-\d{2}-\d{2})(?!\d)/u',$title,$m)) { if ($due!==null) throw new InvalidArgumentException(t('Use only one due date.')); $due=valid_date($m[1]); $title=str_replace($m[0],' ',$title); }
        if (preg_match_all('/(?<![\pL\pN])!(high|normal|low)(?![\pL\pN])/ui',$title)>1) throw new InvalidArgumentException(t('Use only one priority marker.'));
        if (preg_match('/(?<![\pL\pN])!(high|normal|low)(?![\pL\pN])/ui',$title,$m)) { $priority=strtolower($m[1]); $title=str_replace($m[0],' ',$title); }
        if (preg_match_all('/(?<!\d)(?:\d{1,2}(?::[0-5]\d)?\s*(?:am|pm)(?!\pL)|(?:[01]\d|2[0-3]):[0-5]\d(?!\d))/ui',$title)>1) throw new InvalidArgumentException(t('Use only one preferred time.'));
        if (preg_match('/(?<!\d)(\d{1,2})(?::([0-5]\d))?\s*(am|pm)(?!\pL)/ui',$title,$m)) {
            $h=(int)$m[1]; if ($h<1 || $h>12) throw new InvalidArgumentException(t('Use a time between 1am and 12pm.'));
            $time=sprintf('%02d:%02d',($h%12)+(strtolower($m[3])==='pm'?12:0),(int)($m[2] ?? 0)); $title=str_replace($m[0],' ',$title);
        } elseif (preg_match('/(?<!\d)([01]\d|2[0-3]):([0-5]\d)(?!\d)/u',$title,$m)) { $time=$m[0]; $title=str_replace($m[0],' ',$title); }
        if (preg_match_all('/(?<!\w)~(\d{1,3})m\b/u',$title)>1) throw new InvalidArgumentException(t('Use only one time estimate.'));
        if (preg_match('/(?<!\w)~(\d{1,3})m\b/u',$title,$m)) { $minutes=int_range($m[1],5,480); $title=str_replace($m[0],' ',$title); }
        $title=preg_replace('/\s+/u',' ',trim($title)); $title=PlannerService::title($title);
        return ['title'=>$title,'due'=>$due,'time'=>$time,'priority'=>$priority,'estimate_minutes'=>$minutes,'warning'=>$time!==null?'Time is a preference; confirm a free block in Planning.':'Review the parsed task before creating it.'];
    }
    public static function add(string $raw): int { $draft=self::parse($raw); return Db::run('INSERT INTO capture_inbox(raw_text,draft) VALUES (?,?)',[$raw,json_encode($draft,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]); }
    public static function convert(int $id,array $input): int {
        return Db::tx(function () use ($id,$input) {
            $capture=Db::one('SELECT * FROM capture_inbox WHERE id=?',[$id]); if (!$capture || $capture['status']!=='pending') throw new InvalidArgumentException(t('Capture is no longer pending.'));
            $task=PlannerService::saveTask(null,$input); Db::run("UPDATE tasks SET source='capture' WHERE id=?",[$task]);
            Db::run("UPDATE capture_inbox SET task_id=?,status='converted' WHERE id=?",[$task,$id]); return $task;
        });
    }
}
