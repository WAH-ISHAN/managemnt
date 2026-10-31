ALTER TABLE salary_plans ADD COLUMN savings_step_percent REAL NOT NULL DEFAULT 0 CHECK(savings_step_percent BETWEEN 0 AND 100);
