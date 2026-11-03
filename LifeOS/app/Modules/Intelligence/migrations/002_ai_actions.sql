CREATE TABLE ai_applied_actions(job_id TEXT NOT NULL, action_index INTEGER NOT NULL, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(job_id,action_index));
