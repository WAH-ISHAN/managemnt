CREATE TABLE starter_pack_installs (
    pack_id TEXT PRIMARY KEY,
    version INTEGER NOT NULL,
    installed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
