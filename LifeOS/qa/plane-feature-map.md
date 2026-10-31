# Work management feature map

Reference: https://github.com/makeplane/plane, revision `b98e1defb0e089d4505712d89ee9e137fd0935cd` (inspected 2026-10-29). The reference is AGPL-3.0-only. This LifeOS module is an independent PHP/SQLite implementation of the workflow concepts; upstream React/Django source is not copied into the distributed application. The actual Plane service and commercial features are not installed or impersonated.

| Workflow | LifeOS implementation |
| --- | --- |
| Workspaces, project identifiers and project settings | Work management home; typed personal/study/business/client projects, lead, website link and dates |
| Work items and sub-properties | Versioned issues with project sequences, states, priorities, assignees, labels, dates and estimates |
| Sub-issues and relations | Same-project parents, ancestry checks, related/duplicate links and acyclic blocking dependencies |
| Five layouts | List, Kanban with confirmed drag/drop saves, calendar, editable spreadsheet and date-based timeline |
| Search, filters, bulk operations | Validated project-scoped filters, key/text search, atomic bulk changes with versions |
| Cycles | Date ranges, progress, real daily snapshots, remaining-item chart, carry-over |
| Modules | Status/lead/target and linked work-item completion |
| Views | Create/open/edit/remove saved filters and layouts |
| Pages | Safe Markdown, pinning, full saved versions, rollback and linked issue conversion |
| Intake, My work and favourites | Real pending requests, accept/reject, owner assignments and project/issue favourites |
| Collaboration records | Local comments with edit versions, assignee contacts, attachments and activity |
| Time | Manual entries and one persisted timer per workspace; long timers require explicit correction |
| Reports | Actual state, priority, assignee workload, recorded time and progress snapshots |
| Preservation and export | Project archive/restore, issue trash/restore, page trash, CSV/JSON and encrypted whole-app backups |
| LifeOS integration | Sidebar/search, reminders, Documents/backup storage and optional website/business project link |

## Explicit boundaries

This is the local, single-owner LifeOS model. Multi-user online identity, live collaborative editing, public sharing, email/Slack/GitHub delivery, OAuth/SSO and hosted AI calls are not represented by fake buttons or fabricated responses. The user asked to hold cloud/DNS/account setup. Gateway/CMS source remains in the repository, with no live deployment claimed.

Lists use bounded pagination (100, or 500 for month-based calendar/timeline). Date-based layouts query the selected real month; items without required dates are not assigned invented dates. Charts begin with recorded data; prior dates are never fabricated. Project JSON includes structured data; full encrypted backups include attachment bytes. Restoring old encrypted archives requires matching module/migration versions, following the existing LifeOS backup contract.

## Verification

`tests/work_management.php` checks invariants, reference isolation, optimistic versions, hierarchy/dependency cycles, bulk atomicity, cycles, page history, time records and exports. `tests/work_management_http.py` exercises real form/HTTP/database/upload/download flows. The desktop native preflight creates isolated fixtures, checks Kanban drag/drop persistence and captures list/board/calendar/detail/analytics at 1280, 1024 and 720 widths in light/dark themes. Final evidence is recorded separately after release validation.
