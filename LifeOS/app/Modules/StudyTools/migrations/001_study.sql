CREATE TABLE study_records(id INTEGER PRIMARY KEY,kind TEXT NOT NULL,data TEXT NOT NULL,archived INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX study_kind ON study_records(kind,archived);
CREATE TABLE study_card_reviews(id INTEGER PRIMARY KEY,card_id INTEGER NOT NULL REFERENCES study_records(id),reviewed_on TEXT NOT NULL,rating TEXT NOT NULL,next_on TEXT NOT NULL,UNIQUE(card_id,reviewed_on));
CREATE TABLE study_generated_tasks(identity TEXT PRIMARY KEY,task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE RESTRICT);
