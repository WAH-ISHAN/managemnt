CREATE TABLE expense_category_rules (
 id INTEGER PRIMARY KEY,
 profile_id TEXT NOT NULL DEFAULT 'default',
 keyword TEXT NOT NULL,
 keyword_fold TEXT NOT NULL,
 category_id INTEGER NOT NULL REFERENCES expense_categories(id) ON DELETE RESTRICT,
 priority INTEGER NOT NULL DEFAULT 100 CHECK(priority BETWEEN 0 AND 999),
 enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1)),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(profile_id,keyword_fold)
);
CREATE INDEX expense_category_rules_order ON expense_category_rules(profile_id,enabled,priority,id);
