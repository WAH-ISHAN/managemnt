<?php
declare(strict_types=1);

/** Explicit local suggestions. Matching never writes an expense or debits an account. */
final class ExpenseCategoryRules {
    private static function profileId(): string { return class_exists('PersonaService') ? PersonaService::profileId() : 'default'; }
    private static function normalize(string $text): string { return mb_strtolower(trim(preg_replace('/\s+/u',' ',$text) ?? $text),'UTF-8'); }
    public static function all(): array {
        return Db::all('SELECT r.*,c.name category FROM expense_category_rules r JOIN expense_categories c ON c.id=r.category_id WHERE r.profile_id=? ORDER BY r.priority,r.id',[self::profileId()]);
    }
    public static function rule(int $id): array {
        $rule=Db::one('SELECT * FROM expense_category_rules WHERE id=? AND profile_id=?',[$id,self::profileId()]);
        if (!$rule) throw new InvalidArgumentException('Expense category rule not found.');
        return $rule;
    }
    public static function save(?int $id,string $keyword,int $category,int $priority,bool $enabled): int {
        $keyword=trim(preg_replace('/\s+/u',' ',$keyword) ?? $keyword);
        if (mb_strlen($keyword,'UTF-8')<2 || mb_strlen($keyword,'UTF-8')>80 || preg_match('/[\x00-\x1f\x7f]/u',$keyword)) throw new InvalidArgumentException('Use a keyword or phrase of 2 to 80 characters.');
        if ($priority<0 || $priority>999) throw new InvalidArgumentException('Choose a priority from 0 to 999.');
        if (!Db::one('SELECT id FROM expense_categories WHERE id=?',[$category])) throw new InvalidArgumentException('Choose an existing expense category.');
        $fold=self::normalize($keyword);
        $duplicate=Db::one('SELECT id FROM expense_category_rules WHERE profile_id=? AND keyword_fold=?',[self::profileId(),$fold]);
        if ($duplicate && (int)$duplicate['id']!==$id) throw new InvalidArgumentException('This keyword already has a rule.');
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
        if (mb_strlen($description,'UTF-8')>5000) throw new InvalidArgumentException('Keep the description below 5,000 characters.');
        $description=preg_replace('/\s+/u',' ',$description) ?? $description;
        foreach (self::all() as $rule) {
            if (!(int)$rule['enabled']) continue;
            $pattern='/(?<![\pL\pN])'.preg_quote($rule['keyword'],'/').'(?![\pL\pN])/iu';
            if (preg_match($pattern,$description)===1) return ['category_id'=>(int)$rule['category_id'],'category'=>$rule['category'],'keyword'=>$rule['keyword'],'rule_id'=>(int)$rule['id']];
        }
        return null;
    }
}
