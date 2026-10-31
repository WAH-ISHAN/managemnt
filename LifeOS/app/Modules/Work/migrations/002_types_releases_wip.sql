CREATE TABLE work_releases(
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES work_projects(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    starts_on TEXT,
    release_on TEXT,
    status TEXT NOT NULL DEFAULT 'planned' CHECK(status IN ('planned','released','archived')),
    version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id,name)
);
ALTER TABLE work_issues ADD COLUMN issue_type TEXT NOT NULL DEFAULT 'task' CHECK(issue_type IN ('task','bug','story','epic','subtask'));
ALTER TABLE work_issues ADD COLUMN fix_version_id INTEGER REFERENCES work_releases(id) ON DELETE SET NULL;
ALTER TABLE work_states ADD COLUMN wip_limit INTEGER CHECK(wip_limit IS NULL OR wip_limit BETWEEN 1 AND 1000);
CREATE INDEX work_issue_type_release ON work_issues(project_id,issue_type,fix_version_id);
