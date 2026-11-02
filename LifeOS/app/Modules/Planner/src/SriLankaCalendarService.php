<?php
declare(strict_types=1);
final class SriLankaCalendarService {
    public static function year(mixed $input): int {
        if (!is_scalar($input) || !preg_match('/^\d{4}$/D',(string)$input)) throw new InvalidArgumentException('Choose a valid calendar year.');
        $year=(int)$input;
        if ($year<2000 || $year>2100) throw new InvalidArgumentException('Choose a year from 2000 to 2100.');
        return $year;
    }
    public static function seed2026(): void {
        $data=require __DIR__.'/../resources/holidays-2026.php';
        $existing=Db::one("SELECT id,revision FROM holiday_datasets WHERE country='LK' AND year=2026");
        if ($existing && $existing['revision']===$data['revision']) return;
        Db::tx(function () use ($data,$existing): void {
            if ($existing) {
                Db::run('DELETE FROM holidays WHERE dataset_id=?',[(int)$existing['id']]);
                Db::run('UPDATE holiday_datasets SET revision=?,source_title=?,source_url=?,published_on=?,imported_at=CURRENT_TIMESTAMP WHERE id=?',[$data['revision'],$data['source_title'],$data['source_url'],$data['published_on'],(int)$existing['id']]);
                $id=(int)$existing['id'];
            } else {
                $id=Db::run('INSERT INTO holiday_datasets(country,year,revision,source_title,source_url,published_on) VALUES (?,?,?,?,?,?)',['LK',2026,$data['revision'],$data['source_title'],$data['source_url'],$data['published_on']]);
            }
            foreach ($data['rows'] as [$date,$title,$mercantile,$poya]) Db::run('INSERT INTO holidays(dataset_id,date,title_en,is_public,is_bank,is_mercantile,is_poya) VALUES (?,?,?,?,?,?,?)',[$id,$date,$title,1,1,$mercantile,$poya]);
        });
    }
    public static function listing(int $year): array {
        $dataset=Db::one("SELECT * FROM holiday_datasets WHERE country='LK' AND year=?",[$year]);
        return ['dataset'=>$dataset,'holidays'=>$dataset ? Db::all('SELECT * FROM holidays WHERE dataset_id=? ORDER BY date,id',[(int)$dataset['id']]) : [],'school_terms'=>Db::all('SELECT t.*,d.authority,d.source_url FROM school_terms t JOIN school_term_datasets d ON d.id=t.dataset_id WHERE d.year=? ORDER BY t.starts_on,t.id',[$year])];
    }
}
