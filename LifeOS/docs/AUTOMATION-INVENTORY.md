# LifeOS automation inventory (2026-11-11)

This inventory describes the current source and separates automatic work from calculations shown when a page opens. An enabled module is not an enabled automation. All routine rules and their history live in the selected profile's SQLite database. No website deployment, financial transfer, record deletion, or external publication is an allowlisted routine action.

**Labels:** **Automatic** runs after saved configuration; **Partial** has a calculated view, reminder, or separate integration but no general rule; **Manual** needs a person to start it; **Approval** deliberately waits for review. Each row names the next safe event or schedule to connect to the shared engine.

| Area | Current state | Existing behavior and safe next trigger |
| --- | --- | --- |
| Today dashboard | Partial | Saved task, study, finance and progress data are calculated on view. Morning/evening briefs are optional scheduled routines. A record-change event could refresh the open dashboard. |
| Planner | Automatic / Partial | Recurring financial and appointment work, task/habit event hooks, due-date reminders and calendars exist. Conflict detection and suggested slots need a read-only rule. |
| Study focus | Automatic / Partial | Completed sessions persist and emit `focus_completed`; totals are calculated. Break reminders and module-based plans need explicit settings. |
| University | Automatic / Partial | NIBM and private ICS feeds have separate configured sync and cached lectures; academic totals and assignment/exam alerts use saved records. A general lecture/attendance workflow does not yet have a shared backend handler. |
| University Study Tools | Partial / Approval | Attendance, GPA scenarios, grade thresholds, plans, reviews and due-item alerts exist. Generating planner tasks from an assignment/exam plan is a deliberate action. Spaced-review and fee/thesis schedules need shared rules. |
| Employee workspace | Partial | Timesheet, overtime, leave and payslip calculations use recorded entries; invoices, meetings, objectives and credentials have dates. Weekly report and deadline rules need module-specific handlers. No employer sync is implied. |
| Money | Automatic / Partial | Saved income allocation and authorized recurring entries act with duplicate protection. Budgets, balances and cashflow derive from recorded transactions; deadline ladder covers configured obligations. Bank transfers remain outside LifeOS. |
| Investments | Partial | Value, ROI, revenue, cost and goal progress use actual entries. Scheduled valuation reports and unusual-change alerts need explicit thresholds; trading remains manual. |
| Your freedom | Partial | Runway and personal-time totals recalculate from saved data. Optional weekly summaries need a shared report action. |
| Goals & Growth | Partial | Saved steps, prerequisites, milestones and progress are calculated. Inactivity and due-milestone rules need settings. |
| Lessons & growth | Partial / Approval | Approved actions link to tasks; completion and repeated themes are tracked. New improvement actions require review. |
| Review & insights | Automatic / Partial | Aggregated alerts, notification preferences, saved weekly/monthly routine reports and grouped calendar changes exist. Other cross-module insights are on-view calculations. |
| Work management | Partial | Issues, due dates, dependencies, time logs, cycles and reports are saved. Proposed follow-ups and recurring issues need permission-aware handlers; assignments and closures require approval. |
| Work roles | Automatic / Partial | Configured recurring responsibilities produce due work; time allocation is calculated. Role-specific reports need scheduled actions. |
| Career | Partial | Applications, interviews and course dates are saved. Follow-up reminders need per-item settings. |
| Knowledge Hub | Partial / Approval | Search and backlinks use local notes. Summaries require selecting content and consenting before any online AI request. Broken-link and review rules are not scheduled. |
| Documents | Automatic / Partial | Documents are included in authorized encrypted complete backups. Search metadata is maintained. Expiry rules need recorded dates; permanent deletion requires approval. |
| Life & Sri Lanka | Automatic / Partial | Configured recurring commitments and dated records can create reminders; amounts are calculated from saved values. External rates need an authorized provider. |
| Personal essentials | Partial | Subscriptions, renewals and lending/borrowing records have due dates. A unified deadline handler needs per-record opt-in. |
| Small business | Partial | Project income, cost and time totals derive from saved records. Invoice/client follow-ups need a scheduled action. |
| Website Manager | Partial / Approval | Site profiles, connector sessions, renewals and maintenance costs are recorded. Availability/health checks need explicit endpoint settings. Saving a local record never proves live publication. |
| Admin Studio | Partial / Approval | Content and operational records can be managed locally or through a compatible authorized connector. SEO checks could be read-only; publishing requires review and connector authorization. |
| LifeOS AI | Approval | Chat and proposed record writes have review boundaries. Rule drafting/explanation can be added without granting automatic writes or unrestricted record access. |
| Background automation engine | Automatic | Saved daily/weekly/monthly/deadline rules, three activity events, conditions, timezone, catch-up, bounded retries, duplicate-effect claims and execution history remain in the backend. Rule/run/retry methods remain available to authorized backend callers; no standalone page, navigation entry or module checkbox is exposed. Results appear through existing Notifications and Review & insights. |
| Connections & automation | Automatic / Approval | Configured encrypted backup, calendar sync, mailbox previews and scoped local commands have their own jobs. Calendar conflicts and task previews require review. They are not yet represented as common routine jobs. |
| Devices & privacy | Automatic / Approval | Authorized encrypted delivery to paired devices can run; incoming replacement/conflict review protects local data. |
| Settings and profiles | Automatic / Manual | Profile-scoped module/theme/language preferences restore on open; daily snapshots run on use. Complete backup/restore, credential changes and high-impact maintenance require a person. |

The Automations control center is hidden at the user's request. Existing `/automation` bookmarks redirect to Review & insights; saved report links resolve within Insights. Hiding the panel does not enable paused rules, authorize new online requests, or change any personal records. Saving visible module settings preserves the backend service's current enabled/paused state.

## Shared engine and execution modes

- `automation_rules`, `automation_runs`, `automation_effects`, `automation_events`, `automation_messages` and `automation_reports` are durable SQLite tables. The rule/action allowlist, transaction, unique claims and per-profile database prevent duplicate routine effects and cross-profile reads.
- The Windows host calls a loopback-only, process-key-protected tick once per minute. Its resident process continues when the main window is hidden to the tray. **Exit LifeOS** stops it. Windows sign-in startup is an installer option. Browser development runs do not have this native runner.
- Existing separate connectivity, planning and device jobs also tick from that protected endpoint. They have their own configuration and approval boundaries; a single general-purpose persistent queue does not yet coordinate them.
- Backend `runNow()` and `retryRun()` methods retain the routine action allowlist and transactional effect ledger. A one-minute per-rule rate limit prevents repeated manual execution. Failed scheduled slots use the saved retry limit and exponential backoff (capped at 24 hours). Explicit retry applies only to an owned failed slot; a completed slot cannot be retried. The durable run ledger remains stored after restart.
- The current engine has no cross-module dependency graph, per-job priority, universal cancel button or unified persistent queue. These require an additive migration and careful conversion of existing background services before they can be claimed as implemented.

## Approval and integration boundaries

Read-only summaries, calculations and reminders may run after opt-in. Financial transfers, external publishing/deployment/DNS, deletion, AI-proposed writes and replacement of another device's records require an explicit review flow. A connected external feed may sync only with valid authorization; previously cached data remains useful when it is offline. No routine may fetch an arbitrary URL or execute a shell command.

The most valuable next implementation phase is a common durable job queue with lease recovery, priorities and an adapter for existing scheduled services. Then add module-specific *read-only* report and reminder handlers using actual saved rows, with an opt-in control and tests per module. Do not reinterpret this inventory as a claim that those pending workflows already run.
