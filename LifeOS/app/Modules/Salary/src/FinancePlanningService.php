<?php
declare(strict_types=1);
final class FinancePlanningService {
    private static function label(mixed $value,int $limit=150): string {
        $value=trim((string)$value); if ($value==='' || mb_strlen($value)>$limit) throw new InvalidArgumentException('Enter a short title.'); return $value;
    }
    private static function account(int $id,bool $savings=false): void {
        $row=Db::one('SELECT * FROM accounts WHERE id=?',[$id]);
        if (!$row || ($savings && $row['kind']!=='savings')) throw new InvalidArgumentException($savings ? 'Choose a savings account for the emergency fund.' : 'Choose an existing account.');
    }
    private static function category(int $id): void { if (!Db::one('SELECT id FROM expense_categories WHERE id=?',[$id])) throw new InvalidArgumentException('Choose an existing expense category.'); }
    public static function calculate(array $d): array {
        $income=cents($d['income'] ?? ''); $bills=cents($d['bills'] ?? '0',true); $loans=cents($d['loan_payments'] ?? '0',true); $living=cents($d['living'] ?? '0',true);
        $savingRate=cents($d['savings_percent'] ?? '0',true); $investRate=cents($d['investment_percent'] ?? '0',true); $stepInput=$d['savings_step_percent'] ?? '0'; $savingStep=cents($stepInput==='' ? '0' : $stepInput,true);
        if ($savingStep>10000) throw new InvalidArgumentException('Monthly savings increases must be between 0 and 100 percentage points.');
        if ($savingRate>10000 || $investRate>10000 || $savingRate+$investRate>10000) throw new InvalidArgumentException('Savings and investment percentages together must not exceed 100%.');
        $parts=[intdiv($income*$savingRate,10000),intdiv($income*$investRate,10000)];
        $fractions=[($income*$savingRate)%10000,($income*$investRate)%10000]; arsort($fractions,SORT_NUMERIC);
        $extra=intdiv($income*($savingRate+$investRate)+5000,10000)-array_sum($parts);
        foreach ($fractions as $index=>$fraction) { if ($extra--<=0) break; $parts[$index]++; }
        [$savings,$investments]=$parts;
        $essentials=$bills+$loans+$living; $remaining=$income-$essentials-$savings-$investments;
        $suggested=intdiv(max(0,$income-$essentials-$investments),2);
        return ['income'=>$income/100,'bills'=>$bills/100,'loan_payments'=>$loans/100,'living'=>$living/100,'essentials'=>$essentials/100,'savings_percent'=>$savingRate/100,'investment_percent'=>$investRate/100,'savings_step_percent'=>$savingStep/100,'savings'=>$savings/100,'investments'=>$investments/100,'available'=>$remaining/100,'suggested_savings'=>$suggested/100,'suggested_percent'=>round($suggested/$income*100,2),'affordable'=>$remaining>=0];
    }
    public static function savePlan(array $d): void {
        $month=valid_month((string)($d['month'] ?? '')); $math=self::calculate($d);
        $account=optional_id($d['emergency_account_id'] ?? ''); if ($account) self::account($account,true);
        $months=int_range($d['emergency_months'] ?? 3,1,24);
        Db::run('INSERT INTO salary_plans(month,income,bills,loan_payments,living,savings_percent,investment_percent,savings_step_percent,emergency_account_id,emergency_months) VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT(month) DO UPDATE SET income=excluded.income,bills=excluded.bills,loan_payments=excluded.loan_payments,living=excluded.living,savings_percent=excluded.savings_percent,investment_percent=excluded.investment_percent,savings_step_percent=excluded.savings_step_percent,emergency_account_id=excluded.emergency_account_id,emergency_months=excluded.emergency_months,updated_at=CURRENT_TIMESTAMP',[$month,$math['income'],$math['bills'],$math['loan_payments'],$math['living'],$math['savings_percent'],$math['investment_percent'],$math['savings_step_percent'],$account,$months]);
    }
    public static function plan(string $month): ?array {
        valid_month($month); $row=Db::one('SELECT p.*,a.name emergency_account,a.balance emergency_balance FROM salary_plans p LEFT JOIN accounts a ON a.id=p.emergency_account_id WHERE p.month=?',[$month]);
        if (!$row) return null; $row=array_merge($row,self::calculate($row));
        $row['emergency_target']=round($row['essentials']*$row['emergency_months'],2);
        $row['emergency_current']=(float)($row['emergency_balance'] ?? 0);
        $row['emergency_pct']=$row['emergency_target']>0 ? max(0,min(100,$row['emergency_current']/$row['emergency_target']*100)) : 0;
        return $row;
    }
    public static function project(array $plan): array {
        $out=[]; $month=new DateTimeImmutable(valid_month($plan['month']).'-01'); $math=self::calculate($plan); $saving=0; $investing=0;
        $base=(int)round($math['savings_percent']*100); $step=(int)round($math['savings_step_percent']*100);
        // Find the highest affordable hundredth of a percentage point using the
        // same largest-remainder cent allocation as the monthly plan.
        $low=0; $high=10000-(int)round($math['investment_percent']*100);
        while ($low<$high) {
            $candidate=intdiv($low+$high+1,2);
            $trial=self::calculate(array_merge($plan,['savings_percent'=>number_format($candidate/100,2,'.','')]));
            if ($trial['affordable']) $low=$candidate; else $high=$candidate-1;
        }
        $cap=$low;
        for ($i=0;$i<12;$i++) {
            $requested=min(10000,$base+$step*$i); $applied=$step>0 ? min($requested,$cap) : $base;
            $monthly=$step>0 ? self::calculate(array_merge($plan,['savings_percent'=>number_format($applied/100,2,'.','')])) : $math;
            $saving+=(int)round($monthly['savings']*100); $investing+=(int)round($monthly['investments']*100);
            $out[]=['month'=>$month->modify('+'.$i.' month')->format('Y-m'),'savings'=>$saving/100,'investments'=>$investing/100,'monthly_savings'=>$monthly['savings'],'monthly_investments'=>$monthly['investments'],'available'=>$monthly['available'],'requested_savings_percent'=>$requested/100,'applied_savings_percent'=>$applied/100,'affordability_cap_percent'=>$cap/100,'capped'=>$applied<$requested,'affordable'=>$monthly['affordable']];
        }
        return $out;
    }
    public static function applyPlanAllocations(string $month,int $spendingAccount,?int $savingsAccount=null,?int $asset=null): void {
        $plan=self::plan($month); if (!$plan) throw new InvalidArgumentException('Save a salary plan for this month first.');
        self::account($spendingAccount);
        $savings=(int)round($plan['savings_percent']*100); $investments=(int)round($plan['investment_percent']*100); $spending=10000-$savings-$investments; $rules=[];
        if ($savings>0) {
            if (!$savingsAccount) throw new InvalidArgumentException('Choose a savings account for the plan\'s savings share.');
            self::account($savingsAccount,true);
            $rules[]=['name'=>'Savings','percent'=>number_format($savings/100,2,'.',''),'account'=>$savingsAccount,'active'=>1];
        }
        if ($investments>0) {
            if (!$asset || !class_exists('InvestmentService') || !Db::one("SELECT id FROM assets WHERE id=? AND status='active'",[$asset])) throw new InvalidArgumentException('Choose an active investment asset for the plan\'s investment share.');
            $rules[]=['name'=>'Investments','percent'=>number_format($investments/100,2,'.',''),'account'=>$spendingAccount,'asset'=>$asset,'active'=>1];
        }
        if ($spending>0) $rules[]=['name'=>'Living & bills','percent'=>number_format($spending/100,2,'.',''),'account'=>$spendingAccount,'active'=>1];
        SalaryService::saveRules($rules);
    }
    public static function nextDate(string $date,string $interval,int $anchor): string {
        $d=new DateTimeImmutable(valid_date($date));
        if ($interval==='daily') return $d->modify('+1 day')->format('Y-m-d');
        if ($interval==='weekly') return $d->modify('+7 days')->format('Y-m-d');
        if ($interval==='monthly') { $next=$d->modify('first day of next month'); return $next->setDate((int)$next->format('Y'),(int)$next->format('m'),min($anchor,(int)$next->format('t')))->format('Y-m-d'); }
        if ($interval==='yearly') { $year=(int)$d->format('Y')+1; $month=(int)$d->format('m'); $first=new DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month)); return $first->setDate($year,$month,min($anchor,(int)$first->format('t')))->format('Y-m-d'); }
        throw new InvalidArgumentException('Choose a valid recurrence interval.');
    }
    public static function addObligation(array $d): int {
        $title=self::label($d['title'] ?? ''); $kind=(string)($d['kind'] ?? '');
        if (!in_array($kind,['bill','loan'],true)) throw new InvalidArgumentException('Choose a bill or loan.');
        $amount=cents($d['amount'] ?? ''); $due=valid_date((string)($d['due_date'] ?? '')); $interval=(string)($d['interval'] ?? 'none');
        if (!in_array($interval,['none','monthly'],true)) throw new InvalidArgumentException('Choose once or monthly.');
        $account=record_id($d['account_id'] ?? ''); $category=record_id($d['category_id'] ?? ''); self::account($account); self::category($category);
        $balance=$kind==='loan' ? cents($d['outstanding'] ?? '')/100 : null;
        if ($kind==='loan' && $interval==='none' && $amount<(int)round($balance*100)) throw new InvalidArgumentException('Choose monthly for a loan that needs more than one payment.');
        return Db::run('INSERT INTO financial_obligations(title,kind,amount,due_date,interval,anchor_day,account_id,category_id,original_balance,outstanding) VALUES (?,?,?,?,?,?,?,?,?,?)',[$title,$kind,$amount/100,$due,$interval,(int)substr($due,8,2),$account,$category,$balance,$balance]);
    }
    private static function expenseWithinTransaction(string $date,int $category,int $account,int $amount,string $note): int {
        self::account($account); self::category($category);
        $id=Db::run('INSERT INTO expenses(date,category_id,account_id,amount,note) VALUES (?,?,?,?,?)',[$date,$category,$account,$amount/100,$note]);
        Db::run('UPDATE accounts SET balance=ROUND(balance-?,2) WHERE id=?',[$amount/100,$account]); return $id;
    }
    public static function payObligation(int $id,string $due,string $paidDate): int {
        valid_date($due); valid_date($paidDate);
        return Db::tx(function () use ($id,$due,$paidDate) {
            $paid=Db::one("SELECT * FROM financial_payments WHERE obligation_id=? AND due_date=? AND status='paid'",[$id,$due]);
            if ($paid && $paid['expense_id']) return (int)$paid['expense_id'];
            $row=Db::one('SELECT * FROM financial_obligations WHERE id=?',[$id]);
            if (!$row || !$row['active'] || $row['due_date']!==$due) throw new InvalidArgumentException('This payment date changed. Reload the calendar.');
            $amount=(int)round($row['amount']*100);
            if ($row['kind']==='loan') $amount=min($amount,(int)round($row['outstanding']*100));
            if ($amount<=0) throw new InvalidArgumentException('This loan has no remaining balance.');
            $expense=self::expenseWithinTransaction($paidDate,(int)$row['category_id'],(int)$row['account_id'],$amount,ucfirst($row['kind']).': '.$row['title'].' · due '.$due);
            Db::run("INSERT INTO financial_payments(obligation_id,due_date,paid_date,amount,expense_id,status) VALUES (?,?,?,?,?,'paid') ON CONFLICT(obligation_id,due_date) DO UPDATE SET paid_date=excluded.paid_date,amount=excluded.amount,expense_id=excluded.expense_id,status='paid'",[$id,$due,$paidDate,$amount/100,$expense]);
            $outstanding=$row['kind']==='loan' ? max(0,(int)round($row['outstanding']*100)-$amount)/100 : null;
            $active=$row['interval']==='monthly' && ($row['kind']!=='loan' || $outstanding>0) ? 1 : 0;
            $next=$active ? self::nextDate($due,'monthly',(int)$row['anchor_day']) : $due;
            for ($i=0;$active && $i<240 && Db::one("SELECT id FROM financial_payments WHERE obligation_id=? AND due_date=? AND status='paid'",[$id,$next]);$i++) $next=self::nextDate($next,'monthly',(int)$row['anchor_day']);
            Db::run('UPDATE financial_obligations SET outstanding=?,active=?,due_date=? WHERE id=?',[$outstanding,$active,$next,$id]); return $expense;
        });
    }
    // Called inside SalaryService's expense-reversal transaction.
    public static function reverseExpenseLinks(int $expense): void {
        $payment=Db::one("SELECT p.*,o.kind,o.due_date current_due,o.outstanding,o.original_balance FROM financial_payments p JOIN financial_obligations o ON o.id=p.obligation_id WHERE p.expense_id=? AND p.status='paid'",[$expense]);
        if (!$payment) return;
        $outstanding=$payment['kind']==='loan' ? min((int)round($payment['original_balance']*100),(int)round($payment['outstanding']*100)+(int)round($payment['amount']*100))/100 : null;
        Db::run('UPDATE financial_obligations SET active=1,due_date=?,outstanding=? WHERE id=?',[min($payment['due_date'],$payment['current_due']),$outstanding,$payment['obligation_id']]);
        Db::run("UPDATE financial_payments SET expense_id=NULL,status='reversed' WHERE id=?",[$payment['id']]);
    }
    public static function dueItems(int $days=30,?string $today=null): array {
        $today=valid_date($today ?? date('Y-m-d')); $end=(new DateTimeImmutable($today))->modify('+'.max(0,min(365,$days)).' day')->format('Y-m-d');
        return Db::all('SELECT o.*,a.name account,c.name category FROM financial_obligations o JOIN accounts a ON a.id=o.account_id JOIN expense_categories c ON c.id=o.category_id WHERE o.active=1 AND o.due_date<=? ORDER BY o.due_date,o.id',[$end]);
    }
    public static function dailyWeeklyTotals(?string $today=null): array {
        $today=valid_date($today ?? date('Y-m-d')); $start=(new DateTimeImmutable($today))->modify('monday this week')->format('Y-m-d');
        return ['today'=>(float)Db::one('SELECT COALESCE(SUM(amount),0) n FROM expenses WHERE date=?',[$today])['n'],'week'=>(float)Db::one('SELECT COALESCE(SUM(amount),0) n FROM expenses WHERE date>=? AND date<=?',[$start,$today])['n'],'week_start'=>$start];
    }
    public static function outstandingDebt(): float { return (float)Db::one("SELECT COALESCE(SUM(outstanding),0) n FROM financial_obligations WHERE kind='loan'")['n']; }
    public static function addRecurrence(array $d): int {
        $title=self::label($d['title'] ?? ''); $kind=(string)($d['kind'] ?? ''); if (!in_array($kind,['income','expense'],true)) throw new InvalidArgumentException('Choose recurring income or expense.');
        $amount=cents($d['amount'] ?? ''); $next=valid_date((string)($d['next_date'] ?? '')); $interval=(string)($d['interval'] ?? ''); self::nextDate($next,$interval,(int)substr($next,8,2));
        $type=(string)($d['income_type'] ?? 'salary'); if (!in_array($type,['salary','freelance','business','other'],true)) throw new InvalidArgumentException('Choose a valid income type.');
        $account=optional_id($d['account_id'] ?? ''); $category=optional_id($d['category_id'] ?? '');
        if ($account) self::account($account); if ($category) self::category($category);
        if ($kind==='expense' && (!$account || !$category)) throw new InvalidArgumentException('Recurring expenses need an account and category.');
        $active=!empty($d['active']) ? 1 : 0;
        return Db::run('INSERT INTO financial_recurrences(title,kind,amount,income_type,account_id,category_id,interval,next_date,anchor_day,active) VALUES (?,?,?,?,?,?,?,?,?,?)',[$title,$kind,$amount/100,$type,$account,$category,$interval,$next,(int)substr($next,8,2),$active]);
    }
    public static function setRecurrence(int $id,bool $active): void { Db::run('UPDATE financial_recurrences SET active=?,error_text=NULL WHERE id=?',[$active ? 1 : 0,$id]); }
    public static function processDue(?string $today=null,int $cap=50): array {
        $today=valid_date($today ?? date('Y-m-d')); $cap=max(1,min(200,$cap)); $processed=0; $errors=[];
        for ($i=0;$i<$cap;$i++) {
            $r=Db::one('SELECT * FROM financial_recurrences WHERE active=1 AND next_date<=? ORDER BY next_date,id LIMIT 1',[$today]); if (!$r) break;
            $due=$r['next_date']; $next=self::nextDate($due,$r['interval'],(int)$r['anchor_day']);
            if (Db::one('SELECT id FROM financial_occurrences WHERE recurrence_id=? AND due_date=?',[$r['id'],$due])) { Db::run('UPDATE financial_recurrences SET next_date=? WHERE id=?',[$next,$r['id']]); continue; }
            try {
                $save=function (int $entry) use ($r,$due,$next) {
                    Db::run('INSERT INTO financial_occurrences(recurrence_id,due_date,income_id,expense_id) VALUES (?,?,?,?)',[$r['id'],$due,$r['kind']==='income' ? $entry : null,$r['kind']==='expense' ? $entry : null]);
                    Db::run('UPDATE financial_recurrences SET next_date=?,error_text=NULL WHERE id=?',[$next,$r['id']]);
                };
                if ($r['kind']==='income') SalaryService::addIncome($due,$r['title'],(string)$r['amount'],$r['income_type'],$save);
                else Db::tx(function () use ($r,$due,$save) { $id=self::expenseWithinTransaction($due,(int)$r['category_id'],(int)$r['account_id'],(int)round($r['amount']*100),'Recurring: '.$r['title']); $save($id); });
                $processed++;
            } catch (Throwable $e) {
                if (Db::one('SELECT id FROM financial_occurrences WHERE recurrence_id=? AND due_date=?',[$r['id'],$due])) { Db::run('UPDATE financial_recurrences SET next_date=? WHERE id=?',[$next,$r['id']]); continue; }
                $errors[]=$r['title'].': '.$e->getMessage(); Db::run('UPDATE financial_recurrences SET active=0,error_text=? WHERE id=?',[mb_substr($e->getMessage(),0,500),$r['id']]);
            }
        }
        $remaining=(int)Db::one('SELECT COUNT(*) n FROM financial_recurrences WHERE active=1 AND next_date<=?',[$today])['n'];
        return compact('processed','errors','remaining');
    }
}
