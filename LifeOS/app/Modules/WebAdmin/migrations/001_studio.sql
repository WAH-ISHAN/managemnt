CREATE TABLE website_studio_records (
 id INTEGER PRIMARY KEY,
 website_id INTEGER NOT NULL REFERENCES website_profiles(id) ON DELETE RESTRICT,
 section TEXT NOT NULL,
 title TEXT NOT NULL,
 status TEXT NOT NULL,
 data TEXT NOT NULL,
 version INTEGER NOT NULL DEFAULT 1,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 deleted_at TEXT
);
CREATE INDEX website_studio_listing ON website_studio_records(website_id,section,deleted_at,updated_at,id);
CREATE TABLE website_studio_activity (
 id INTEGER PRIMARY KEY,
 website_id INTEGER NOT NULL REFERENCES website_profiles(id) ON DELETE CASCADE,
 action TEXT NOT NULL,
 section TEXT NOT NULL,
 record_title TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX website_studio_activity_site ON website_studio_activity(website_id,id);
CREATE TABLE website_studio_media (
 id INTEGER PRIMARY KEY,
 website_id INTEGER NOT NULL REFERENCES website_profiles(id) ON DELETE RESTRICT,
 filename TEXT NOT NULL,
 storage_name TEXT NOT NULL UNIQUE,
 mime TEXT NOT NULL,
 bytes INTEGER NOT NULL,
 sha256 TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
