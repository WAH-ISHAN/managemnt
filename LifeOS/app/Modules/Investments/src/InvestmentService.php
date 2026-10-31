<?php
declare(strict_types=1);
final class InvestmentService {
    public const TYPES=['business'=>'Business','trading'=>'Trading','deposit'=>'Fixed deposit','cash'=>'Cash / savings','other'=>'Other'];
    public static function asset(int $id): array { $a=Db::one('SELECT * FROM assets WHERE id=?',[$id]); if (!$a) throw new InvalidArgumentException('Asset not found.'); return $a; }
    public static function create(string $name,string $type,string $date,string $notes): int {
        if (!isset(self::TYPES[$type])) throw new InvalidArgumentException('Choose a valid asset type.'); valid_date($date);
        return Db::run('INSERT INTO assets(name,type,start_date,notes) VALUES (?,?,?,?)',[$name,$type,$date,$notes]);
    }
    public static function entry(int $id,string $date,string $type,mixed $amount,string $note,?int $account=null): int {
        self::asset($id); valid_date($date);
        if (!in_array($type,['deposit','withdraw','valuation'],true)) throw new InvalidArgumentException('Choose a valid entry type.');
        $n=cents($amount,$type==='valuation');
        if ($account && ($type==='valuation' || !Db::one('SELECT id FROM accounts WHERE id=?',[$account]))) throw new InvalidArgumentException('Select an existing account for a deposit or withdrawal. Valuations do not move cash.');
        return Db::tx(function () use ($id,$date,$type,$n,$note,$account) {
            $entry=Db::run('INSERT INTO asset_transactions(asset_id,date,type,amount,note,account_id) VALUES (?,?,?,?,?,?)',[$id,$date,$type,$n/100,$note,$account]);
            if ($account) Db::run('UPDATE accounts SET balance=ROUND(balance+?,2) WHERE id=?',[$type==='deposit' ? -$n/100 : $n/100,$account]);
            return $entry;
        });
    }
    public static function deleteEntry(int $asset,int $id): void {
        Db::tx(function () use ($asset,$id) {
            $row=Db::one('SELECT * FROM asset_transactions WHERE id=? AND asset_id=?',[$id,$asset]);
            if (!$row) throw new InvalidArgumentException('Entry not found.');
            if ($row['income_id']) throw new InvalidArgumentException('This deposit belongs to income. Delete the income entry in Money to reverse it.');
            if ($row['account_id']) Db::run('UPDATE accounts SET balance=ROUND(balance+?,2) WHERE id=?',[$row['type']==='deposit' ? $row['amount'] : -$row['amount'],$row['account_id']]);
            Db::run('DELETE FROM asset_transactions WHERE id=?',[$id]);
        });
    }
    public static function summary(int $id,?string $asOf=null): array {
        $until=$asOf ?? date('Y-m-d');
        $rows=Db::all('SELECT * FROM asset_transactions WHERE asset_id=? AND date<=? ORDER BY date,id',[$id,$until]);
        $dep=0; $wd=0; $current=0;
        // Cash movements after the last valuation change the current value too.
        foreach ($rows as $t) {
            $n=(int)round($t['amount']*100);
            if ($t['type']==='valuation') $current=$n;
            elseif ($t['type']==='deposit') { $dep+=$n; $current+=$n; }
            else { $wd+=$n; $current-=$n; }
        }
        $profit=$current+$wd-$dep;
        return ['invested'=>($dep-$wd)/100,'current'=>$current/100,'profit'=>$profit/100,'roi'=>$dep>0 ? $profit/$dep*100 : 0,'deposited'=>$dep/100,'withdrawn'=>$wd/100];
    }
    public static function allWithSummary(): array { return array_map(fn($a)=>$a+self::summary((int)$a['id']),Db::all('SELECT * FROM assets ORDER BY status,name')); }
    public static function netWorth(): float { return array_sum(array_column(array_filter(self::allWithSummary(),fn($a)=>$a['status']==='active'),'current')); }
    public static function metrics(int $id): array { return Db::all('SELECT *,ROUND(revenue-cost,2) profit FROM asset_metrics WHERE asset_id=? ORDER BY month',[$id]); }
    public static function series(int $id): array {
        $dates=Db::all('SELECT DISTINCT date FROM asset_transactions WHERE asset_id=? AND date<=? ORDER BY date',[$id,date('Y-m-d')]);
        return array_map(fn($r)=>['date'=>$r['date'],'amount'=>self::summary($id,$r['date'])['current']],$dates);
    }
    public static function goals(): array {
        return array_map(function ($g) { $current=$g['asset_id'] ? self::summary((int)$g['asset_id'])['current'] : 0; return $g+['current'=>$current,'pct'=>max(0,min(100,$current/$g['target_amount']*100))]; },Db::all('SELECT g.*,a.name asset FROM goals g LEFT JOIN assets a ON a.id=g.asset_id ORDER BY g.status,g.due_date IS NULL,g.due_date,g.id'));
    }
}
