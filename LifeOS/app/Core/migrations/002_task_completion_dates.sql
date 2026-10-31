ALTER TABLE tasks ADD COLUMN completed_at TEXT;
CREATE INDEX tasks_completed_at ON tasks(completed_at,status);
CREATE TRIGGER tasks_completion_date AFTER UPDATE OF status ON tasks
WHEN NEW.status<>OLD.status
BEGIN
 UPDATE tasks SET completed_at=CASE WHEN NEW.status='done' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=NEW.id;
END;
CREATE TRIGGER tasks_created_complete AFTER INSERT ON tasks
WHEN NEW.status='done' AND NEW.completed_at IS NULL
BEGIN
 UPDATE tasks SET completed_at=CURRENT_TIMESTAMP WHERE id=NEW.id;
END;
