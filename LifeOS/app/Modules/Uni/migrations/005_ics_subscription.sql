CREATE TABLE uni_ics_events (
    id INTEGER PRIMARY KEY,
    uid TEXT NOT NULL UNIQUE,
    appointment_id INTEGER REFERENCES planner_appointments(id) ON DELETE SET NULL,
    details_hash TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    source_status TEXT NOT NULL CHECK(source_status IN ('scheduled','cancelled'))
);
CREATE INDEX uni_ics_events_appointment ON uni_ics_events(appointment_id);
