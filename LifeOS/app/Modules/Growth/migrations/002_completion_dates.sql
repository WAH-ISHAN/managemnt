ALTER TABLE personal_goal_steps ADD COLUMN completed_at TEXT;
CREATE INDEX goal_steps_completed_at ON personal_goal_steps(completed_at,status);
CREATE TRIGGER goal_step_completion_date AFTER UPDATE OF status ON personal_goal_steps
WHEN NEW.status<>OLD.status
BEGIN
 UPDATE personal_goal_steps SET completed_at=CASE WHEN NEW.status='done' THEN CURRENT_TIMESTAMP ELSE NULL END WHERE id=NEW.id;
END;
