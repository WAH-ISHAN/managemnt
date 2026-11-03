<?php
declare(strict_types=1);
final class SriLankaCalendarService {
    public static function seedBundled():void{
        self::seed2026();$data=require __DIR__.'/../resources/holidays-2027.php';$existing=Db::one("SELECT id FROM holiday_datasets WHERE country='LK' AND year=2027");
        if(!$existing)Db::tx(function()use($data){$id=Db::run('INSERT INTO holiday_datasets(country,year,revision,source_title,source_url,published_on) VALUES(?,?,?,?,?,?)',['LK',2027,$data['revision'],$data['source_title'],$data['source_url'],$data['published_on']]);foreach($data['rows']as [$date,$title,$m,$p])Db::run('INSERT INTO holidays(dataset_id,date,title_en,is_public,is_bank,is_mercantile,is_poya) VALUES(?,?,?,1,1,?,?)',[$id,$date,$title,$m,$p]);});
        $terms=require __DIR__.'/../resources/school-terms-2027.php';foreach($terms['calendars']as $authority=>$rows)if(!Db::one('SELECT id FROM school_term_datasets WHERE authority=? AND year=2027',[$authority]))Db::tx(function()use($terms,$authority,$rows){$id=Db::run('INSERT INTO school_term_datasets(authority,year,revision,source_title,source_url,published_on) VALUES(?,2027,?,?,?,?)',[$authority,$terms['revision'],$terms['source_title'],$terms['source_url'],$terms['published_on']]);foreach($rows as [$label,$start,$end])Db::run('INSERT INTO school_terms(dataset_id,label,starts_on,ends_on) VALUES(?,?,?,?)',[$id,$label,$start,$end]);});
    }
    public static function schoolDay(string $date,?string $authority=null):array{
        valid_date($date);self::seedBundled();$year=(int)substr($date,0,4);$holiday=Db::one('SELECT title_en FROM holidays WHERE date=? AND is_public=1',[$date]);if($holiday)return ['open'=>false,'reason'=>$holiday['title_en']];if((int)date('N',strtotime($date))>=6)return ['open'=>false,'reason'=>'Weekend'];
        if($authority!==null&&$authority!==''){$datasets=Db::all('SELECT id FROM school_term_datasets WHERE year=? AND authority=?',[$year,$authority]);if(!$datasets)return ['open'=>null,'reason'=>'No term calendar for this authority/year.'];$term=Db::one('SELECT t.label FROM school_terms t JOIN school_term_datasets d ON d.id=t.dataset_id WHERE d.year=? AND d.authority=? AND t.starts_on<=? AND t.ends_on>=?',[$year,$authority,$date,$date]);return ['open'=>$term!==null,'reason'=>$term['label']??'Outside the selected school terms'];}
        return ['open'=>null,'reason'=>'Weekday; select your school calendar to confirm term dates.'];
    }
    public static function year(mixed $input): int {
        if (!is_scalar($input) || !preg_match('/^\d{4}$/D',(string)$input)) throw new InvalidArgumentException(t('Choose a valid calendar year.'));
        $year=(int)$input;
        if ($year<2000 || $year>2100) throw new InvalidArgumentException(t('Choose a year from 2000 to 2100.'));
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
            foreach ($data['rows'] as $row) {
                [$date,$title,$mercantile,$poya]=$row;
                Db::run('INSERT INTO holidays(dataset_id,date,title_en,is_public,is_bank,is_mercantile,is_poya) VALUES (?,?,?,?,?,?,?)',[$id,$date,$title,$row[4] ?? 1,1,$mercantile,$poya]);
            }
        });
    }
    public static function listing(int $year): array {
        $dataset=Db::one("SELECT * FROM holiday_datasets WHERE country='LK' AND year=?",[$year]);
        return ['dataset'=>$dataset,'holidays'=>$dataset ? Db::all('SELECT * FROM holidays WHERE dataset_id=? ORDER BY date,id',[(int)$dataset['id']]) : [],'school_terms'=>Db::all('SELECT t.*,d.authority,d.source_url FROM school_terms t JOIN school_term_datasets d ON d.id=t.dataset_id WHERE d.year=? ORDER BY t.starts_on,t.id',[$year])];
    }
}
