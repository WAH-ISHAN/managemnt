<?php
declare(strict_types=1);

/** Explicit local suggestions. Matching never writes an expense or debits an account. */
final class ExpenseCategoryRules {
    private static function profileId(): string { return class_exists('PersonaService') ? PersonaService::profileId() : 'default'; }
    private static function checkedText(string $text): string {
        if (!mb_check_encoding($text,'UTF-8')) throw new InvalidArgumentException(t('Use valid UTF-8 text.'));
        $text=trim(preg_replace('/\s+/u',' ',$text));
        if (preg_match('/[\x00-\x1f\x7f]/u',$text)) throw new InvalidArgumentException(t('Remove control characters from the text.'));
        return $text;
    }
    private static function normalize(string $text): string { return mb_convert_case(self::checkedText($text),MB_CASE_FOLD,'UTF-8'); }
    public static function all(): array {
        return Db::all('SELECT r.*,c.name category FROM expense_category_rules r JOIN expense_categories c ON c.id=r.category_id WHERE r.profile_id=? ORDER BY r.priority,r.id',[self::profileId()]);
    }
    public static function rule(int $id): array {
        $rule=Db::one('SELECT * FROM expense_category_rules WHERE id=? AND profile_id=?',[$id,self::profileId()]);
        if (!$rule) throw new InvalidArgumentException(t('Expense category rule not found.'));
        return $rule;
    }
    public static function save(?int $id,string $keyword,int $category,int $priority,bool $enabled): int {
        $keyword=self::checkedText($keyword);
        if (mb_strlen($keyword,'UTF-8')<2 || mb_strlen($keyword,'UTF-8')>80) throw new InvalidArgumentException(t('Use a keyword or phrase of 2 to 80 characters.'));
        if ($priority<0 || $priority>999) throw new InvalidArgumentException(t('Choose a priority from 0 to 999.'));
        if (!Db::one('SELECT id FROM expense_categories WHERE id=?',[$category])) throw new InvalidArgumentException(t('Choose an existing expense category.'));
        $fold=self::normalize($keyword);
        // Re-fold saved display text too: older installs stored lowercase, which
        // differs from Unicode case folding for final sigma and long s.
        foreach (Db::all('SELECT id,keyword FROM expense_category_rules WHERE profile_id=?',[self::profileId()]) as $existing) {
            if ((int)$existing['id']!==$id && self::normalize($existing['keyword'])===$fold) throw new InvalidArgumentException(t('This keyword already has a rule.'));
        }
        if ($id!==null) {
            self::rule($id);
            Db::run('UPDATE expense_category_rules SET keyword=?,keyword_fold=?,category_id=?,priority=?,enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND profile_id=?',[$keyword,$fold,$category,$priority,$enabled?1:0,$id,self::profileId()]);
            return $id;
        }
        return Db::run('INSERT INTO expense_category_rules(profile_id,keyword,keyword_fold,category_id,priority,enabled) VALUES (?,?,?,?,?,?)',[self::profileId(),$keyword,$fold,$category,$priority,$enabled?1:0]);
    }
    public static function remove(int $id): void {
        self::rule($id);
        Db::run('DELETE FROM expense_category_rules WHERE id=? AND profile_id=?',[$id,self::profileId()]);
    }
    /** Returns no category when no enabled rule matches; the user must choose one. */
    public static function suggest(string $description): ?array {
        if (mb_strlen($description,'UTF-8')>5000) throw new InvalidArgumentException(t('Keep the description below 5,000 characters.'));
        $description=self::checkedText($description);
        foreach (self::all() as $rule) {
            if (!(int)$rule['enabled']) continue;
            $pattern='/(?<![\pL\pN\pM\p{Cf}\p{Pc}])'.preg_quote($rule['keyword'],'/').'(?![\pL\pN\pM\p{Cf}\p{Pc}])/iu';
            if (preg_match($pattern,$description)===1) return ['category_id'=>(int)$rule['category_id'],'category'=>$rule['category'],'keyword'=>$rule['keyword'],'rule_id'=>(int)$rule['id']];
        }
        return null;
    }
}
