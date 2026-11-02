<?php
declare(strict_types=1);

/** Conservative generic parser for pasted debit alerts; no bank account is accessed. */
final class ExpenseAlertImport {
    public const PARSER_VERSION='generic-debit-v1';
    private static function profileId(): string { return class_exists('PersonaService') ? PersonaService::profileId() : 'default'; }
    private static function canonical(string $message): string {
        if (!mb_check_encoding($message,'UTF-8') || mb_strlen($message,'UTF-8')>3000) throw new InvalidArgumentException('Paste an alert below 3,000 characters.');
        $value=mb_strtolower(trim(preg_replace('/\s+/u',' ',$message) ?? ''),'UTF-8');
        if ($value==='') throw new InvalidArgumentException('Paste a debit or purchase alert.');
        return $value;
    }
    public static function fingerprint(string $message): string { return hash('sha256',self::canonical($message)); }
    public static function duplicate(string $message): bool {
        return (bool)Db::one('SELECT id FROM expense_alert_imports WHERE profile_id=? AND fingerprint=?',[self::profileId(),self::fingerprint($message)]);
    }
    private static function date(string $message): array {
        if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/u',$message,$match)) return [valid_date($match[1]),false];
        if (preg_match('/\b([0-3]\d)[\/.-]([01]\d)[\/.-](20\d{2})\b/u',$message,$match)) return [valid_date($match[3].'-'.$match[2].'-'.$match[1]),false];
        return [date('Y-m-d'),true];
    }
    public static function preview(string $message): array {
        $canonical=self::canonical($message);
        if (!preg_match('/\b(?:debited|debit|spent|purchase|purchased|payment|paid)\b/u',$canonical)) throw new InvalidArgumentException('This generic parser needs an explicit debit, purchase or payment marker. Enter the expense manually if the alert uses another format.');
        preg_match_all('/(?:\bLKR\b|\bRs\.?)(?:\s|:)*((?:[0-9]{1,3}(?:,[0-9]{3})+|[0-9]{1,10})(?:\.[0-9]{2})?)/iu',$message,$amounts,PREG_OFFSET_CAPTURE);
        $candidates=[];
        foreach ($amounts[1] as [$raw,$offset]) {
            $near=mb_strtolower(substr($message,max(0,$offset-40),strlen($raw)+80),'UTF-8');
            $balance=mb_strtolower(substr($message,max(0,$offset-24),24+strlen($raw)+10),'UTF-8');
            if (preg_match('/\b(?:balance|available|remaining|limit)\b/u',$balance)) continue;
            if (!preg_match('/\b(?:debited|debit|spent|purchase|purchased|payment|paid)\b/u',$near)) continue;
            $amount=str_replace(',','',$raw);
            cents($amount);
            $candidates[]=$amount;
        }
        if (count($candidates)!==1) throw new InvalidArgumentException('Could not identify one debit amount safely. Enter the expense manually or use a supported alert format.');
        [$date,$assumedDate]=self::date($message);
        return ['amount'=>$candidates[0],'date'=>$date,'assumedDate'=>$assumedDate,
            'parserVersion'=>self::PARSER_VERSION,'duplicate'=>self::duplicate($message),
            'suggestion'=>ExpenseCategoryRules::suggest($message)];
    }
    public static function post(string $message,string $date,mixed $amount,int $category,int $account,string $note): int {
        self::preview($message);
        if (self::duplicate($message)) throw new InvalidArgumentException('This alert was already imported. Review the existing expense before posting another.');
        $fingerprint=self::fingerprint($message);
        try {
            return SalaryService::addExpense($date,$category,$account,$amount,$note,function (int $expense) use ($fingerprint): void {
                Db::run('INSERT INTO expense_alert_imports(profile_id,fingerprint,parser_version,expense_id) VALUES (?,?,?,?)',[self::profileId(),$fingerprint,self::PARSER_VERSION,$expense]);
            });
        } catch (PDOException $error) {
            if (self::duplicate($message)) throw new InvalidArgumentException('This alert was already imported. Review the existing expense before posting another.',0,$error);
            throw $error;
        }
    }
}
