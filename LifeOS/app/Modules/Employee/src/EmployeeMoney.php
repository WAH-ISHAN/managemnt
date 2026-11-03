<?php
declare(strict_types=1);
final class EmployeeMoney {
 public static function links(int $id):array{return Db::one('SELECT * FROM employee_money_links WHERE record_id=?',[$id])??['expense_id'=>null,'income_id'=>null];}
 public static function assertEditable(string $kind,array $d,?int $id):void{
  if($id===null||!in_array($kind,['claims','invoices'],true))return;
  $link=self::links($id);$old=EmployeeService::get($id,$kind)['fields'];
  if($link['expense_id']&&($d['amount']!==$old['amount']||$d['date']!==$old['date']))throw new InvalidArgumentException(t('Reverse the linked Money expense before changing its amount or date.'));
  if($link['income_id'])foreach($kind==='claims'?['reimbursed','state']:['paid','state','currency']as $f)if($d[$f]!==$old[$f])throw new InvalidArgumentException(t('Reverse the linked Money income before changing the recorded receipt.'));
 }
 private static function requireMoney():void{if(!module_enabled('Salary'))throw new InvalidArgumentException(t('Enable Money before posting.'));}
 private static function same(int $id,string $kind,array $expected,string $column):void{if(EmployeeService::get($id,$kind)['fields']!==$expected||self::links($id)[$column])throw new InvalidArgumentException(t('The entry changed or was already posted. Review it again.'));}
 public static function expense(int $id,int $account,int $category):int{
  self::requireMoney();$d=EmployeeService::get($id,'claims')['fields'];if(self::links($id)['expense_id'])throw new InvalidArgumentException(t('Claim expense already posted.'));
  return SalaryService::addExpense($d['date'],$category,$account,(string)$d['amount'],'Claim: '.mb_substr($d['title'],0,150),function($expense)use($d,$id){self::same($id,'claims',$d,'expense_id');Db::run('INSERT INTO employee_money_links(record_id,expense_id) VALUES(?,?) ON CONFLICT(record_id) DO UPDATE SET expense_id=excluded.expense_id',[$id,$expense]);});
 }
 public static function receipt(string $kind,int $id,string $date):int{
  self::requireMoney();if(!in_array($kind,['claims','invoices'],true))throw new InvalidArgumentException(t('Choose a claim or invoice.'));$d=EmployeeService::get($id,$kind)['fields'];
  if($d['state']!=='paid'||self::links($id)['income_id'])throw new InvalidArgumentException(t('Mark the entry paid and post its receipt once.'));
  if($kind==='invoices'&&$d['currency']!=='LKR')throw new InvalidArgumentException(t('Record this foreign-currency receipt with its dated conversion in Money and the exchange tracker.'));
  $amount=$kind==='claims'?$d['reimbursed']:$d['paid'];if($amount<=0)throw new InvalidArgumentException(t('Enter a positive received amount.'));
  return SalaryService::addIncome($date,$kind==='claims'?'Claim reimbursement: '.mb_substr($d['title'],0,150):'Invoice '.EmployeeService::invoiceNumber($id),(string)$amount,$kind==='claims'?'other':'freelance',function($income)use($kind,$id,$d){self::same($id,$kind,$d,'income_id');Db::run('INSERT INTO employee_money_links(record_id,income_id) VALUES(?,?) ON CONFLICT(record_id) DO UPDATE SET income_id=excluded.income_id',[$id,$income]);});
 }
}
