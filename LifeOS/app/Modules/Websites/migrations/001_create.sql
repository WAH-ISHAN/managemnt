CREATE TABLE website_profiles (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    base_url TEXT NOT NULL,
    server_address TEXT NOT NULL DEFAULT '',
    stack TEXT NOT NULL DEFAULT '',
    connector_url TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    last_connected_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE website_operations (
    id INTEGER PRIMARY KEY,
    website_id INTEGER NOT NULL REFERENCES website_profiles(id) ON DELETE CASCADE,
    action TEXT NOT NULL,
    collection TEXT NOT NULL DEFAULT '',
    record_id TEXT NOT NULL DEFAULT '',
    outcome TEXT NOT NULL CHECK(outcome IN ('succeeded','failed')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX website_operations_profile ON website_operations(website_id,id DESC);
