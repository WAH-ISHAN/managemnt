<?php
declare(strict_types=1);
final class SalaryService {
    public static function addIncome(string $date, string $source, mixed $amount, string $type = 'salary', ?callable $afterSave=null): int {
        valid_date($date); $total = cents($amount);
        if (trim($source) === '' || mb_strlen($source)>200) throw new InvalidArgumentException('Enter an income source.');
        if (!in_array($type,['salary','freelance','business','other'],true)) throw new InvalidArgumentException('Choose a valid income type.');
        return Db::tx(function () use ($date,$source,$total,$type,$afterSave) {
            $rules = Db::all('SELECT * FROM allocation_rules WHERE active=1 ORDER BY sort,id');
            $basis = array_map(fn($r)=>cents((string)$r['percent'],true),$rules);
            $sum = array_sum($basis);
            if ($sum>10000) throw new InvalidArgumentException('Active rules cannot exceed 100%.');
            // Largest remainders keep every cent accounted for, including small incomes.
            $shares = []; $remainders = []; $distributed = 0;
            foreach ($basis as $i=>$b) { $shares[$i] = intdiv($total*$b,10000); $remainders[$i] = ($total*$b)%10000; $distributed += $shares[$i]; }
            $target = intdiv($total*$sum+5000,10000); arsort($remainders,SORT_NUMERIC);
            $left = $target-$distributed;
            foreach ($remainders as $i=>$remainder) { if ($left--<=0) break; $shares[$i]++; }
            $id = Db::run('INSERT INTO income(date,source,amount,type) VALUES (?,?,?,?)',[$date,trim($source),$total/100,$type]);
            foreach ($rules as $i=>$rule) {
                if (!in_array($rule['target_type'],['account','asset'],true)) throw new InvalidArgumentException('This rule needs a supported destination.');
                if ($rule['target_type']==='asset') {
                    $asset=Db::one('SELECT * FROM assets WHERE id=? AND status=?',[$rule['asset_target_id'],'active']);
                    if (!$asset) throw new InvalidArgumentException('A rule points to a closed or missing asset. Update it in Settings.');
                    Db::run('INSERT INTO allocations(income_id,rule_id,name,percent,account_id,amount,asset_id) VALUES (?,?,?,?,?,?,?)',[$id,$rule['id'],$rule['name'],$rule['percent'],$rule['target_id'],$shares[$i]/100,$asset['id']]);
                    Db::run('INSERT INTO asset_transactions(asset_id,date,type,amount,note,income_id) VALUES (?,?,?,?,?,?)',[$asset['id'],$date,'deposit',$shares[$i]/100,'Auto allocation: '.$rule['name'],$id]);
                } else self::credit($id,(int)$rule['id'],$rule['name'],(float)$rule['percent'],(int)$rule['target_id'],$shares[$i]);
            }
            if ($total>$target) self::credit($id,null,'Unallocated',(10000-$sum)/100,(int)Db::setting('unallocated_account'),$total-$target);
            if ($afterSave) $afterSave($id);
            return $id;
        });
    }
    private static function credit(int $income, ?int $rule, string $name, float $percent, int $account, int $amount): void {
        if (!Db::one('SELECT id FROM accounts WHERE id=?',[$account])) throw new InvalidArgumentException('A rule destination account is missing.');
        Db::run('INSERT INTO allocations(income_id,rule_id,name,percent,account_id,amount) VALUES (?,?,?,?,?,?)',[$income,$rule,$name,$percent,$account,$amount/100]);
        Db::run('UPDATE accounts SET balance=ROUND(balance+?,2) WHERE id=?',[$amount/100,$account]);
    }
    public static function deleteIncome(int $id): void {
        Db::tx(function () use ($id) {
            if (!Db::one('SELECT id FROM income WHERE id=?',[$id])) throw new InvalidArgumentException('Income entry not found.');
            foreach (Db::all('SELECT account_id,amount,asset_id FROM allocations WHERE income_id=?',[$id]) as $part) if (!$part['asset_id']) Db::run('UPDATE accounts SET balance=ROUND(balance-?,2) WHERE id=?',[$part['amount'],$part['account_id']]);
            Db::run('DELETE FROM income WHERE id=?',[$id]);
        });
    }
    public static function addExpense(string $date, int $category, int $account, mixed $amount, string $note,?callable $afterSave=null): int {
        valid_date($date); $n = cents($amount); if (mb_strlen($note)>1000) throw new InvalidArgumentException('Keep the note below 1,000 characters.');
        return Db::tx(function () use ($date,$category,$account,$n,$note,$afterSave) {
            if (!Db::one('SELECT id FROM expense_categories WHERE id=?',[$category]) || !Db::one('SELECT id FROM accounts WHERE id=?',[$account])) throw new InvalidArgumentException('Choose an existing category and account.');
            $id = Db::run('INSERT INTO expenses(date,category_id,account_id,amount,note) VALUES (?,?,?,?,?)',[$date,$category,$account,$n/100,trim($note)]);
            Db::run('UPDATE accounts SET balance=ROUND(balance-?,2) WHERE id=?',[$n/100,$account]);
            if ($afterSave) $afterSave($id);
            return $id;
        });
    }
    public static function deleteExpense(int $id,?callable $afterDelete=null): void {
        Db::tx(function () use ($id,$afterDelete) {
            $row = Db::one('SELECT * FROM expenses WHERE id=?',[$id]); if (!$row) throw new InvalidArgumentException('Expense entry not found.');
            if (class_exists('FinancePlanningService')) FinancePlanningService::reverseExpenseLinks($id);
            Db::run('UPDATE accounts SET balance=ROUND(balance+?,2) WHERE id=?',[$row['amount'],$row['account_id']]); Db::run('DELETE FROM expenses WHERE id=?',[$id]);
            if ($afterDelete) $afterDelete($id);
        });
    }
    public static function saveRules(array $rows): void {
        if (count($rows)>30) throw new InvalidArgumentException('Use up to 30 allocation rules.');
        $valid = []; $sum=0;
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? '')); if ($name==='') continue;
            if (mb_strlen($name)>100) throw new InvalidArgumentException('Rule names must be under 100 characters.');
            $percent = cents($row['percent'] ?? '',true); $account = record_id($row['account'] ?? '');
            $asset=optional_id($row['asset'] ?? '');
            if ($asset && !Db::one('SELECT id FROM assets WHERE id=? AND status=?',[$asset,'active'])) throw new InvalidArgumentException('Choose an active asset.');
            if ($percent>10000 || !Db::one('SELECT id FROM accounts WHERE id=?',[$account])) throw new InvalidArgumentException('Choose a valid percentage and account.');
            $active = !empty($row['active']) ? 1 : 0; if ($active) $sum += $percent;
            $valid[] = [$name,$percent/100,$account,count($valid),$active,$asset ? 'asset' : 'account',$asset];
        }
        if ($sum>10000) throw new InvalidArgumentException('Active allocation rules total more than 100%. Reduce a percentage and try again.');
        Db::tx(function () use ($valid) {
            // Snapshots in allocations survive removal of the previous rule set.
            Db::run('DELETE FROM allocation_rules');
            foreach ($valid as $row) Db::run('INSERT INTO allocation_rules(name,percent,target_id,sort,active,target_type,asset_target_id) VALUES (?,?,?,?,?,?,?)',$row);
        });
    }
    public static function overview(string $month): array {
        $start = $month . '-01'; $end = (new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $income = (float)Db::one('SELECT COALESCE(SUM(amount),0) n FROM income WHERE date>=? AND date<?',[$start,$end])['n'];
        $expenses = (float)Db::one('SELECT COALESCE(SUM(amount),0) n FROM expenses WHERE date>=? AND date<?',[$start,$end])['n'];
        $saved = (float)Db::one("SELECT COALESCE(SUM(a.amount),0) n FROM allocations a JOIN income i ON i.id=a.income_id JOIN accounts c ON c.id=a.account_id WHERE c.kind='savings' AND a.asset_id IS NULL AND i.date>=? AND i.date<?",[$start,$end])['n'];
        $budgets = Db::all('SELECT c.*,COALESCE(SUM(e.amount),0) spent FROM expense_categories c LEFT JOIN expenses e ON e.category_id=c.id AND e.date>=? AND e.date<? GROUP BY c.id ORDER BY c.name',[$start,$end]);
        return compact('income','expenses','saved','budgets');
    }
    public static function monthlySeries(int $months=6): array {
        $out=[]; $now=new DateTimeImmutable('first day of this month');
        for ($i=$months-1;$i>=0;$i--) { $m=$now->modify('-'.$i.' months')->format('Y-m'); $s=self::overview($m); $out[]=['month'=>$m,'income'=>$s['income'],'expenses'=>$s['expenses']]; } return $out;
    }
}
