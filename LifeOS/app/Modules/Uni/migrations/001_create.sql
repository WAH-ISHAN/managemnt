CREATE TABLE uni_modules(id INTEGER PRIMARY KEY, code TEXT, name TEXT NOT NULL, credits INTEGER NOT NULL DEFAULT 0 CHECK(credits>=0 AND credits<=100), semester TEXT, grade TEXT);
CREATE TABLE assignments(id INTEGER PRIMARY KEY, module_id INTEGER NOT NULL REFERENCES uni_modules(id) ON DELETE CASCADE, title TEXT NOT NULL, due_date TEXT, status TEXT NOT NULL DEFAULT 'todo' CHECK(status IN ('todo','doing','done')), weight REAL CHECK(weight>=0 AND weight<=100), marks REAL CHECK(marks>=0 AND marks<=100));
CREATE TABLE exams(id INTEGER PRIMARY KEY, module_id INTEGER NOT NULL REFERENCES uni_modules(id) ON DELETE CASCADE, date TEXT NOT NULL, venue TEXT, marks REAL CHECK(marks>=0 AND marks<=100));
CREATE TABLE timetable(id INTEGER PRIMARY KEY, module_id INTEGER NOT NULL REFERENCES uni_modules(id) ON DELETE CASCADE, day INTEGER NOT NULL CHECK(day BETWEEN 1 AND 7), start_time TEXT NOT NULL, end_time TEXT NOT NULL, venue TEXT);
CREATE INDEX idx_assignment_due ON assignments(status,due_date);
INSERT INTO settings(key,value) VALUES ('grade_points','{"A+":4,"A":4,"A-":3.7,"B+":3.3,"B":3,"B-":2.7,"C+":2.3,"C":2,"C-":1.7,"D":1,"F":0}');
