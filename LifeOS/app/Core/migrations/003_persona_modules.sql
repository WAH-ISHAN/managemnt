CREATE TABLE local_profiles (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    persona TEXT NOT NULL,
    locale TEXT NOT NULL DEFAULT 'en' CHECK(locale IN ('en','si','ta')),
    template TEXT NOT NULL DEFAULT '',
    dashboard_layout TEXT NOT NULL DEFAULT 'balanced',
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE modules (
    profile_id TEXT NOT NULL REFERENCES local_profiles(id) ON DELETE CASCADE,
    module_id TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK(enabled IN (0,1)),
    sort_order INTEGER NOT NULL DEFAULT 50 CHECK(sort_order BETWEEN 0 AND 999),
    PRIMARY KEY(profile_id,module_id)
);
