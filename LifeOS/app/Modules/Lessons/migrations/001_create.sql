CREATE TABLE lessons(id INTEGER PRIMARY KEY, area TEXT NOT NULL, what_happened TEXT NOT NULL, root_cause TEXT, lesson TEXT, action TEXT, task_id INTEGER UNIQUE REFERENCES tasks(id) ON DELETE SET NULL, status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','applied','verified')), created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE weekly_reviews(id INTEGER PRIMARY KEY, week TEXT NOT NULL UNIQUE, wins TEXT, fails TEXT, score INTEGER NOT NULL CHECK(score BETWEEN 1 AND 10), focus TEXT);
CREATE INDEX idx_lessons_created ON lessons(created_at,area);
