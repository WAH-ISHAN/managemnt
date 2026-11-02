CREATE TABLE expense_alert_imports (
 id INTEGER PRIMARY KEY,
 profile_id TEXT NOT NULL DEFAULT 'default',
 fingerprint TEXT NOT NULL,
 parser_version TEXT NOT NULL,
 expense_id INTEGER NOT NULL REFERENCES expenses(id) ON DELETE CASCADE,
 imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(profile_id,fingerprint),
 UNIQUE(expense_id)
);
CREATE INDEX expense_alert_imports_profile ON expense_alert_imports(profile_id,imported_at DESC);
