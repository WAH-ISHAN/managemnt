CREATE TABLE desktop_panel_notes(
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX desktop_panel_note_latest ON desktop_panel_notes(id DESC);
