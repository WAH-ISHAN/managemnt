CREATE TABLE automation_rules(
 id INTEGER PRIMARY KEY,
 profile_id TEXT NOT NULL DEFAULT 'default',
 template_key TEXT,
 name TEXT NOT NULL,
 enabled INTEGER NOT NULL DEFAULT 0 CHECK(enabled IN (0,1)),
 trigger TEXT NOT NULL CHECK(trigger IN ('daily','weekly','monthly','deadline')),
 conditions TEXT NOT NULL CHECK(json_valid(conditions)),
 actions TEXT NOT NULL CHECK(json_valid(actions)),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(profile_id,template_key)
);
CREATE INDEX automation_rules_enabled ON automation_rules(profile_id,enabled);
CREATE TABLE automation_runs(
 id INTEGER PRIMARY KEY,
 rule_id INTEGER NOT NULL REFERENCES automation_rules(id) ON DELETE CASCADE,
 slot TEXT NOT NULL,
 status TEXT NOT NULL CHECK(status IN ('running','complete','failed','skipped')),
 detail TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(rule_id,slot)
);
CREATE TABLE automation_messages(
 id INTEGER PRIMARY KEY,
 profile_id TEXT NOT NULL DEFAULT 'default',
 run_id INTEGER NOT NULL REFERENCES automation_runs(id) ON DELETE CASCADE,
 title TEXT NOT NULL,
 body TEXT NOT NULL,
 route TEXT NOT NULL DEFAULT '/automation',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX automation_messages_profile ON automation_messages(profile_id,id DESC);
CREATE TABLE automation_reports(
 id INTEGER PRIMARY KEY,
 profile_id TEXT NOT NULL DEFAULT 'default',
 run_id INTEGER NOT NULL REFERENCES automation_runs(id) ON DELETE CASCADE,
 kind TEXT NOT NULL CHECK(kind IN ('weekly','monthly')),
 starts_on TEXT NOT NULL,
 ends_before TEXT NOT NULL,
 summary TEXT NOT NULL CHECK(json_valid(summary)),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
