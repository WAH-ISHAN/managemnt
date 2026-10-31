<?php
declare(strict_types=1);

final class WebsiteCostService {
    public const KINDS=['hosting'=>'Hosting / VPS','domain'=>'Domain / renewal','maintenance'=>'Maintenance','development'=>'Development','other'=>'Other'];
    private static function text(mixed $value,int $max,string $label): string {
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || str_contains($value,"\0") || mb_strlen($value)>$max) throw new InvalidArgumentException('Enter a valid '.$label.' of up to '.$max.' characters.');
        return trim($value);
    }
    public static function add(int $website,array $data): int {
        $site=WebsiteService::profile($website);
        $date=valid_date(self::text($data['date'] ?? '',10,'date'));
        $kind=self::text($data['kind'] ?? '',30,'cost type');
        if (!isset(self::KINDS[$kind])) throw new InvalidArgumentException('Choose a website cost type.');
        $amount=cents($data['amount'] ?? '');
        $vendor=self::text($data['vendor'] ?? '',120,'vendor');
        $notes=self::text($data['notes'] ?? '',1000,'cost note');
        $account=optional_id($data['account_id'] ?? '');
        $insert=static fn(?int $expense=null)=>Db::run('INSERT INTO website_costs(website_id,cost_date,kind,amount,vendor,notes,expense_id,money_recorded) VALUES (?,?,?,?,?,?,?,?)',[$website,$date,$kind,$amount/100,$vendor,$notes,$expense,$expense!==null ? 1 : 0]);
        if ($account===null) return $insert();
        $category=optional_id($data['category_id'] ?? '');
        if ($category===null) throw new InvalidArgumentException('Choose a Money expense category for this account payment.');
        $costId=0;
        $note=mb_substr('Website: '.$site['name'].' | '.self::KINDS[$kind].($vendor!=='' ? ' | '.$vendor : '').($notes!=='' ? ' | '.$notes : ''),0,1000);
        // Both rows and the account debit commit or roll back together.
        SalaryService::addExpense($date,$category,$account,$data['amount'],$note,static function(int $expense)use($insert,&$costId): void { $costId=$insert($expense); });
        return $costId;
    }
    public static function remove(int $website,int $id): void {
        WebsiteService::profile($website);
        $cost=Db::one('SELECT * FROM website_costs WHERE id=? AND website_id=?',[$id,$website]);
        if (!$cost) throw new InvalidArgumentException('Website cost not found.');
        $remove=static fn()=>Db::run('DELETE FROM website_costs WHERE id=? AND website_id=?',[$id,$website]);
        if ($cost['expense_id']!==null) SalaryService::deleteExpense((int)$cost['expense_id'],static function(int $expenseId)use($remove): void { $remove(); });
        else $remove();
    }
    public static function summaries(?string $month=null): array {
        $month=valid_month($month ?? date('Y-m'));
        $start=$month.'-01'; $end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $rows=Db::all('SELECT website_id,COUNT(*) records,COALESCE(SUM(CAST(ROUND(amount*100) AS INTEGER)),0) total_cents,COALESCE(SUM(CASE WHEN cost_date>=? AND cost_date<? THEN CAST(ROUND(amount*100) AS INTEGER) ELSE 0 END),0) month_cents FROM website_costs GROUP BY website_id',[$start,$end]);
        $sites=[]; $total=0; $period=0; $count=0;
        foreach ($rows as $row) {
            $sites[(int)$row['website_id']]=['total'=>(int)$row['total_cents']/100,'month_total'=>(int)$row['month_cents']/100,'count'=>(int)$row['records']];
            $total+=(int)$row['total_cents']; $period+=(int)$row['month_cents']; $count+=(int)$row['records'];
        }
        return ['sites'=>$sites,'total'=>$total/100,'month_total'=>$period/100,'count'=>$count,'month'=>$month];
    }
    public static function records(int $website,?string $month): array {
        WebsiteService::profile($website);
        $sql='SELECT c.*,a.name money_account FROM website_costs c LEFT JOIN expenses e ON e.id=c.expense_id LEFT JOIN accounts a ON a.id=e.account_id WHERE c.website_id=?'; $args=[$website];
        if ($month!==null) {
            $start=valid_month($month).'-01'; $end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
            $sql.=' AND c.cost_date>=? AND c.cost_date<?'; array_push($args,$start,$end);
        }
        return Db::all($sql.' ORDER BY c.cost_date DESC,c.id DESC LIMIT 100',$args);
    }
}
