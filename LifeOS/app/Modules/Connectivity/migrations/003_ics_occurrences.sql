ALTER TABLE connectivity_events ADD COLUMN series_uid TEXT;
ALTER TABLE connectivity_events ADD COLUMN occurrence_at TEXT;
CREATE INDEX connectivity_series_occurrences ON connectivity_events(provider,series_uid,occurrence_at);
