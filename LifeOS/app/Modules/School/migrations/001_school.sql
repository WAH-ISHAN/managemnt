CREATE TABLE school_records(id INTEGER PRIMARY KEY,kind TEXT NOT NULL,data TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','done','archived')),created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,completed_on TEXT);
CREATE INDEX school_kind ON school_records(kind,status);
CREATE TABLE school_xp(record_id INTEGER PRIMARY KEY REFERENCES school_records(id),points INTEGER NOT NULL CHECK(points>0));
CREATE TABLE school_reward_requests(id INTEGER PRIMARY KEY,reward_id INTEGER NOT NULL REFERENCES school_records(id),cost INTEGER NOT NULL CHECK(cost>0),status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),guardian_id TEXT,requested_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,decided_at TEXT);
CREATE TABLE school_guardian_audit(id INTEGER PRIMARY KEY,guardian_id TEXT NOT NULL,action TEXT NOT NULL,record_id INTEGER,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
