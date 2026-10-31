# Open-source work-management references

Inspected on 2026-10-30 for the LifeOS Work expansion. The user's [awesome-jira-alternatives list](https://github.com/stefan-ernst/awesome-jira-alternatives) led to two focused references: Kanboard for lightweight board limits, and Redmine for issue types and releases. The list is a discovery source; the checked-out repositories and their licenses supply the implementation evidence below.

## Local clones and provenance

All repositories were shallow-cloned with `git clone --depth 1` into `.tools/jira-references/`. The three working trees were clean after inspection.

| Repository | Local directory | Inspected commit | License evidence |
| --- | --- | --- | --- |
| [awesome-jira-alternatives](https://github.com/stefan-ernst/awesome-jira-alternatives) | `.tools/jira-references/awesome-jira-alternatives` | `750429fdae1467d6d8004065ae9b906a684effd4` (2026-05-04) | `LICENSE`: MIT, copyright Stefan Ernst |
| [Kanboard](https://github.com/kanboard/kanboard) | `.tools/jira-references/kanboard` | `5bd2adb459208ded59fdc4ec652f4f6b3cef8d57` (2026-10-28) | `LICENSE`: MIT, copyright Frederic Guillot |
| [Redmine](https://github.com/redmine/redmine) | `.tools/jira-references/redmine` | `f3aac8ef46f04644e3fba0ffec348c74f1e15405` (2026-10-29) | `doc/COPYING`: GPL version 2; application source headers allow version 2 or later |

Downloaded application scripts were not executed. The reference application source remains in `.tools/`; it is outside the LifeOS runtime and installer payload. LifeOS additions use its existing PHP/SQLite services, validation, routes and views. No third-party application source was copied into LifeOS by this research task. If future changes copy source, their license and attribution obligations must be reviewed for that actual reuse.

## Source patterns inspected

| Capability | Exact reference evidence | Suitable LifeOS behavior |
| --- | --- | --- |
| Column work-in-progress limits | Kanboard [`app/Model/ColumnModel.php`](https://github.com/kanboard/kanboard/blob/5bd2adb459208ded59fdc4ec652f4f6b3cef8d57/app/Model/ColumnModel.php) stores `task_limit` in column creation/update; [`app/Template/column/edit.php`](https://github.com/kanboard/kanboard/blob/5bd2adb459208ded59fdc4ec652f4f6b3cef8d57/app/Template/column/edit.php) exposes a nonnegative limit; [`app/Template/board/table_column.php`](https://github.com/kanboard/kanboard/blob/5bd2adb459208ded59fdc4ec652f4f6b3cef8d57/app/Template/board/table_column.php) presents total count/limit; [`app/Template/board/table_tasks.php`](https://github.com/kanboard/kanboard/blob/5bd2adb459208ded59fdc4ec652f4f6b3cef8d57/app/Template/board/table_tasks.php) marks over-limit columns. | A saved optional limit per Work state, a count/limit badge using the full active state population, and clear over-limit feedback. If LifeOS blocks entering a full state, that is a separately implemented LifeOS rule; this source inspection establishes Kanboard's saved limits and warning presentation. |
| Actual issue types | Redmine [`app/models/tracker.rb`](https://github.com/redmine/redmine/blob/f3aac8ef46f04644e3fba0ffec348c74f1e15405/app/models/tracker.rb) models named trackers, default status, project membership and field availability; [`app/models/issue.rb`](https://github.com/redmine/redmine/blob/f3aac8ef46f04644e3fba0ffec348c74f1e15405/app/models/issue.rb) links issues to trackers. [Official tracker documentation](https://www.redmine.org/projects/redmine/wiki/RedmineIssueTrackingSetup) describes types and their workflows. | A real type field with Task, Bug, Story and Epic, visible on forms/cards and usable as a project filter. Default existing issues to Task during migration. This is smaller than tracker-specific workflow/field schemes. |
| Project versions and releases | Redmine [`app/models/version.rb`](https://github.com/redmine/redmine/blob/f3aac8ef46f04644e3fba0ffec348c74f1e15405/app/models/version.rb) models project versions with a name, effective date, status, issue associations and completion; `VERSION_STATUSES` is open/locked/closed. `Issue#assignable_versions` in [`app/models/issue.rb`](https://github.com/redmine/redmine/blob/f3aac8ef46f04644e3fba0ffec348c74f1e15405/app/models/issue.rb) selects assignable versions and preserves an issue's existing version. [Official project settings](https://www.redmine.org/projects/redmine/wiki/RedmineProjectSettings#Versions) explains version assignment and roadmap progress. | Project-owned releases with name, dates, status, issue assignment and completion totals. Validate that the issue and release share a project. Preserve existing issue assignments when changing release status. |
| Configurable workflow transitions | Redmine [`app/models/workflow_transition.rb`](https://github.com/redmine/redmine/blob/f3aac8ef46f04644e3fba0ffec348c74f1e15405/app/models/workflow_transition.rb) stores permitted status changes by tracker/role and updates them transactionally. | A useful later extension after issue types. Role permissions require a user-account design beyond LifeOS's current local owner. |

## Priorities against the LifeOS audit

The initial [Jira comparison](jira-feature-map.md) identified issue types and releases as missing, and WIP limits as a board gap. These three features were selected as bounded additions from these clones: each fits the existing local Work model, has direct source evidence, and can be implemented without another database server or container stack. Custom-field schemes, role-based workflows, advanced planning, automation and multi-user collaboration remain distinct capabilities and must not be counted as implemented by adding type/release/limit fields.

## LifeOS implementation scope

The Work expansion now provides original PHP/SQLite implementations of these concepts:

- Fixed Task/Bug/Story/Epic/Subtask types; Subtask requires a parent, and type appears in forms, filters, cards, spreadsheets, bulk changes and exports.
- Project-owned release records with Planned/Released/Archived status, dates and completion totals; issue Fix version assignment is project-scoped and edits reject stale versions. Released or archived releases remain assignable, and changing release status preserves issue state/assignment.
- Optional state WIP limits with whole-project accepted active-item counts and visible over-limit warnings. Limits are advisory and do not block workflow changes.

The migration is `app/Modules/Work/migrations/002_types_releases_wip.sql`; the new release service is `app/Modules/Work/src/WorkReleaseService.php`. Existing issue/state values are preserved, while migrated issues receive Task/no-release and states receive an unset WIP limit. Independent verification passed 86 Work service checks, 95 Work HTTP checks and PHP lint for the changed services/routes. The [Jira feature map](jira-feature-map.md) retains the remaining scheme, hierarchy, release-lock/merge/notes, swimlane and ranking limitations.

Research validation consisted of successful clones, exact commit identification, clean working-tree checks, license-file/header inspection and the cited source reads. It did not execute or benchmark Kanboard or Redmine, or establish complete Jira parity. The LifeOS service/HTTP verification above is distinct from the desktop visual preflight.
