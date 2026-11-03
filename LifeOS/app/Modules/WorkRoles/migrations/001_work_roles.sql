CREATE TABLE work_roles (
 id INTEGER PRIMARY KEY, name TEXT NOT NULL, color TEXT NOT NULL DEFAULT '#0071e3', org TEXT NOT NULL DEFAULT '',
 hourly_rate REAL NOT NULL DEFAULT 0 CHECK(hourly_rate>=0), income_source TEXT NOT NULL DEFAULT '',
 is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)), template_key TEXT UNIQUE);
CREATE TABLE role_responsibilities (
 id INTEGER PRIMARY KEY, role_id INTEGER NOT NULL REFERENCES work_roles(id), title TEXT NOT NULL,
 recurrence TEXT NOT NULL CHECK(recurrence IN ('daily','weekly','monthly')), est_minutes INTEGER NOT NULL CHECK(est_minutes BETWEEN 5 AND 480),
 week_day INTEGER NOT NULL DEFAULT 1 CHECK(week_day BETWEEN 1 AND 7), month_day INTEGER NOT NULL DEFAULT 1 CHECK(month_day BETWEEN 1 AND 31),
 is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)), template_key TEXT,
 UNIQUE(id,role_id), UNIQUE(role_id,template_key));
CREATE TABLE work_tasks (
 id INTEGER PRIMARY KEY, role_id INTEGER NOT NULL REFERENCES work_roles(id), responsibility_id INTEGER,
 title TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','doing','blocked','done')),
 priority INTEGER NOT NULL DEFAULT 2 CHECK(priority BETWEEN 1 AND 3), due_date TEXT NOT NULL, done_at TEXT,
 occurrence_key TEXT, FOREIGN KEY(responsibility_id,role_id) REFERENCES role_responsibilities(id,role_id),
 UNIQUE(id,role_id), UNIQUE(responsibility_id,occurrence_key));
CREATE TABLE role_time_logs (
 id INTEGER PRIMARY KEY, role_id INTEGER NOT NULL REFERENCES work_roles(id), task_id INTEGER,
 started_at TEXT NOT NULL, ended_at TEXT, note TEXT NOT NULL DEFAULT '', hourly_rate REAL NOT NULL DEFAULT 0 CHECK(hourly_rate>=0),
 FOREIGN KEY(task_id,role_id) REFERENCES work_tasks(id,role_id), CHECK(ended_at IS NULL OR ended_at>=started_at));
CREATE UNIQUE INDEX role_one_running_timer ON role_time_logs((1)) WHERE ended_at IS NULL;
CREATE INDEX role_tasks_today ON work_tasks(due_date,role_id,status);
CREATE INDEX role_logs_period ON role_time_logs(started_at,ended_at);
CREATE TABLE role_daily_logs (
 id INTEGER PRIMARY KEY, role_id INTEGER NOT NULL REFERENCES work_roles(id), date TEXT NOT NULL,
 did TEXT NOT NULL DEFAULT '', blocked TEXT NOT NULL DEFAULT '', next TEXT NOT NULL DEFAULT '',
 lesson_id INTEGER REFERENCES lessons(id) ON DELETE SET NULL, UNIQUE(role_id,date));
CREATE TABLE role_income_links (
 id INTEGER PRIMARY KEY, role_id INTEGER NOT NULL REFERENCES work_roles(id), period_start TEXT NOT NULL, period_end TEXT NOT NULL,
 income_id INTEGER REFERENCES income(id) ON DELETE SET NULL, UNIQUE(role_id,period_start,period_end));
