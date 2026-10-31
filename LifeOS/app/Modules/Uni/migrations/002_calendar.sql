CREATE TABLE uni_calendar_events (
    id INTEGER PRIMARY KEY,
    batch_id TEXT NOT NULL,
    remote_id TEXT NOT NULL,
    appointment_id INTEGER REFERENCES planner_appointments(id) ON DELETE SET NULL,
    title TEXT NOT NULL,
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    location TEXT NOT NULL DEFAULT '',
    remote_status TEXT NOT NULL,
    synced_at TEXT NOT NULL,
    UNIQUE(batch_id, remote_id)
);
CREATE INDEX uni_calendar_event_start ON uni_calendar_events(batch_id, starts_at);
