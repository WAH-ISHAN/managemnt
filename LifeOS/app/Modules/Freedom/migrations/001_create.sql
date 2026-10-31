CREATE TABLE freedom_time (
 id INTEGER PRIMARY KEY,
 date TEXT NOT NULL,
 category TEXT NOT NULL CHECK(category IN ('rest','relationships','health','experiences','learning')),
 minutes INTEGER NOT NULL CHECK(minutes BETWEEN 1 AND 1440),
 note TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX freedom_time_date ON freedom_time(date,id);
