ALTER TABLE uni_calendar_events ADD COLUMN details_hash TEXT NOT NULL DEFAULT '';
CREATE TABLE uni_calendar_notifications (
    id INTEGER PRIMARY KEY,
    batch_id TEXT NOT NULL,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE INDEX uni_calendar_notification_latest ON uni_calendar_notifications(id DESC);
