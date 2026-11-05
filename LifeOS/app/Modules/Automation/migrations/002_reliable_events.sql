ALTER TABLE automation_runs ADD COLUMN attempts INTEGER NOT NULL DEFAULT 1;
ALTER TABLE automation_runs ADD COLUMN retry_at TEXT;
CREATE TABLE automation_effects (
 profile_id TEXT NOT NULL, effect_key TEXT NOT NULL,
 run_id INTEGER NOT NULL REFERENCES automation_runs(id) ON DELETE CASCADE,
 PRIMARY KEY(profile_id,effect_key)
);
CREATE TABLE automation_events (
 id INTEGER PRIMARY KEY, kind TEXT NOT NULL, entity_id INTEGER NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX automation_events_kind ON automation_events(kind,id);
CREATE TRIGGER automation_task_completed AFTER UPDATE OF status ON tasks
WHEN NEW.status='done' AND OLD.status<>'done'
BEGIN INSERT INTO automation_events(kind,entity_id) VALUES ('task_completed',NEW.id); END;
CREATE TRIGGER automation_focus_completed AFTER UPDATE OF status ON focus_sessions
WHEN NEW.status='complete' AND OLD.status<>'complete'
BEGIN INSERT INTO automation_events(kind,entity_id) VALUES ('focus_completed',NEW.id); END;
CREATE TRIGGER automation_habit_checked AFTER INSERT ON planner_habit_checks
BEGIN INSERT INTO automation_events(kind,entity_id) VALUES ('habit_checked',NEW.habit_id); END;
