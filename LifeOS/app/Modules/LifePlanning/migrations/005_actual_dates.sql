ALTER TABLE life_festival_items ADD COLUMN actual_on TEXT;
ALTER TABLE life_groceries ADD COLUMN purchased_on TEXT;
ALTER TABLE life_followups ADD COLUMN auto_task INTEGER NOT NULL DEFAULT 0;
CREATE TABLE life_followup_tasks(followup_id INTEGER NOT NULL REFERENCES life_followups(id),due_on TEXT NOT NULL,task_id INTEGER NOT NULL REFERENCES tasks(id) ON DELETE RESTRICT,PRIMARY KEY(followup_id,due_on));
