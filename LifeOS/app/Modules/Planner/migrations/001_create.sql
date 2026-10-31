CREATE TABLE planner_habits(id INTEGER PRIMARY KEY, title TEXT NOT NULL, notes TEXT, active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)), created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE planner_habit_checks(habit_id INTEGER NOT NULL REFERENCES planner_habits(id) ON DELETE CASCADE, date TEXT NOT NULL, PRIMARY KEY(habit_id,date));
CREATE TABLE planner_routines(id INTEGER PRIMARY KEY, title TEXT NOT NULL, time TEXT NOT NULL, days TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)));
CREATE TABLE planner_routine_checks(routine_id INTEGER NOT NULL REFERENCES planner_routines(id) ON DELETE CASCADE, date TEXT NOT NULL, PRIMARY KEY(routine_id,date));
CREATE TABLE planner_appointments(id INTEGER PRIMARY KEY, title TEXT NOT NULL, starts_at TEXT NOT NULL, ends_at TEXT NOT NULL, location TEXT, notes TEXT, reminder_minutes INTEGER NOT NULL DEFAULT 15 CHECK(reminder_minutes BETWEEN 0 AND 10080), status TEXT NOT NULL DEFAULT 'scheduled' CHECK(status IN ('scheduled','done','cancelled')));
CREATE INDEX planner_appointment_start ON planner_appointments(starts_at,status);
