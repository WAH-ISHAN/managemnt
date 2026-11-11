ALTER TABLE planner_appointments ADD COLUMN kind TEXT NOT NULL DEFAULT 'event' CHECK(kind IN ('event','renewal','exam'));
