# LifeOS persona update roadmap

This is the product scope for the next LifeOS development cycle, recorded from the 100-feature proposal. Persona-based setup, local automation, Sinhala/Tamil/English access and Sri Lankan daily needs are the core direction. A persona selects useful modules and templates; the person can change those choices later.

The complete list is a roadmap, not a claim that all 100 features ship together. The implemented foundation covers persona onboarding, saved module choices and sidebar ordering for the current `default` profile, an English/Sinhala/Tamil interface shell with English fallback, an allowlisted local automation builder and a resident runner. Targeted tests have passed; full release QA remains. See [foundation verification](../qa/persona-automation-foundation.md) for tested scope and limits. This work does not change the release version or build an installer.

## Delivery order

Preserve the requested priority: **#1 → #9 → #10/#14/#15 → #17/#18 → #79 → #6**. Start the translation infrastructure with onboarding so later modules can use it; complete Sinhala coverage after the first automation and Sri Lanka feature slices. Add a persona module only when its workflows work with real saved records.

| Phase | Scope | Completion requirement |
| --- | --- | --- |
| 1. Persona foundation | #1, #2, basic #7; infrastructure for #6 | All six personas select useful installed modules; saved preferences survive restart; disabled modules cannot be opened through a direct URL; existing personal records survive upgrade. |
| 2. Local automation foundation | #9, #10, #11, #14, #15; basic #16 | Validated trigger/condition/action rules, explicit enable controls, idempotent runs, visible failures and actual-data HTML reports; resident operation continues when the main window is hidden. |
| 3. Expense automation | #17, #18, extend #19 | Keyword rules and bank-alert parsing produce a reviewable expense draft; repeated input cannot silently duplicate a debit; user-confirmed expenses use the existing financial transaction services. |
| 4. Sri Lanka calendar and language | #79, complete #6 | Versioned, attributable official holiday data with holiday types and valid years; school terms stored separately; Sinhala/Tamil/English navigation and core workflows reviewed for readable fonts and wording. |
| 5. Persona packs | Employee #37–#50; University #51–#64; School #65–#78; freelancer/business/job-seeker templates | Complete useful workflows one pack at a time. School features depend on guardian permissions before exposing parent controls or reports. |
| 6. Profile isolation and capture | #3–#5, #8, #97 | Independent database, uploads, backups, sessions and PIN per profile; deliberate switching; guardian policy enforced on the server; application lock tested across tray, panel and background work. |
| 7. Automation extensions and local AI | #12, #13, #20–#36 | Reversible planning actions, event links and tested local adapters; network access, credentials, scripts, active-window tracking and microphones require explicit opt-in configuration. |
| 8. Life and Sri Lanka modules | #80–#94 | Reuse existing finance/planner records where appropriate; rates carry their effective dates and remain editable; calculations show assumptions. |
| 9. Platform expansion | #95, #96, #98–#100 | Authenticated local companion, encrypted sync design and recovery, portable imports, module contracts, undo/audit/privacy controls. |

## Status meanings

- **Existing**: a relevant workflow is already described in the repository README and source. This does not mean every requested extension exists.
- **Extend**: usable building blocks exist, but the requested feature needs additional work.
- **Foundation verified**: implemented in this cycle and checked by targeted automated tests; full release acceptance is pending. Remaining scope is stated explicitly.
- **Planned**: recorded scope, with no implementation claim.

## Complete feature register

### Core / persona system

| # | Feature | Status and scope |
| --- | --- | --- |
| 1 | Persona onboarding wizard: Employee, University student, School student, Freelancer, Business owner, Job seeker | **Foundation verified.** All six personas select installed-module defaults and dashboard emphasis for the current `default` profile. Changing persona preserves existing personal records. |
| 2 | Module on/off and sidebar customization | **Foundation verified.** Persist module enablement and sidebar order for `default`; core pages stay available. Disabled module routes are blocked while their tables and records remain. |
| 3 | Multiple profiles on one PC, each with its own DB and PIN | **Planned.** A persona is not a security boundary. Profile isolation includes documents, backups, sessions and webview state. |
| 4 | Parent/Guardian mode with read-only child view and rules | **Planned.** Explicit guardian relationship, constrained read access and policy changes with audit history. |
| 5 | Kid mode with big buttons, simple language and colour | **Extend.** School persona has larger controls and simple colour accents. Full child-oriented wording, workflows, accessibility review and guardian controls remain planned. |
| 6 | Sinhala / Tamil / English switch | **Foundation verified / Extend.** Onboarding and navigation shell switch languages with English fallback. Full module content, validation messages and reports still need translation coverage and language review. |
| 7 | Starter template packs: HND student, Grade 11, Software engineer employee | **Foundation verified / Extend.** These three starter choices select the matching persona's module/layout settings. They do not populate task, subject, course or goal templates; those richer packs remain planned. |
| 8 | Global Quick Capture inbox and natural-language parsing | **Extend.** Desktop quick notes/to-dos exist; global capture, an inbox and parsing such as `call amma tomorrow 5pm !high` are planned. |

### Automation engine

| # | Feature | Status and scope |
| --- | --- | --- |
| 9 | Visual trigger → condition → action rule builder | **Foundation verified.** Form-based builder with validated daily/weekly/monthly/deadline triggers, local-time/weekday/open-task conditions and allowlisted local actions. No arbitrary PHP, SQL or shell execution. |
| 10 | Automatic morning brief: tasks, classes/meetings, bills, weather | **Foundation verified / Extend.** Brief uses actual saved tasks, appointments, timetable classes and due payments from enabled modules. Weather is not implemented. |
| 11 | Evening shutdown routine with review and tomorrow prompt | **Foundation verified.** Local scheduled reminder to review the day and choose tomorrow's priorities; no automatic task changes. |
| 12 | Reschedule overdue tasks to the next free slot | **Planned.** Respect working/study windows, deadlines and protected appointments; show changed tasks and allow reversal. |
| 13 | Automatic daily time-blocking planner | **Planned.** Add duration, availability, conflicts and user-pinned blocks before scheduling. |
| 14 | Deadline countdown ladder: 7 / 3 / 1 days and same-day | **Foundation verified.** Checks saved tasks, assignments, exams and unpaid obligations from enabled modules. Repeated ticks execute each rule's daily slot once. Historical missed days are not replayed. |
| 15 | Automatic weekly review report: tasks, spending, habits, focus | **Foundation verified.** Saved summary for the previous complete Monday–Sunday week, rendered as local printable HTML with period and unavailable-data states. PDF uses the print dialog. |
| 16 | Automatic monthly financial summary | **Foundation verified.** Previous calendar month's recorded income/expenses, scheduled on the first day of the month; no assumed transactions or historical catch-up. |
| 17 | Expense keyword → category rules | **Planned.** Ordered rules, visible match reason, user override and a default uncategorized outcome. |
| 18 | Pasted bank SMS/email alert → expense | **Planned.** Bank-specific parser versions, a preview, duplicate detection and explicit posting to money accounts. |
| 19 | Recurring bills with due reminders | **Extend.** Recurring financial entries and bill/loan reminders already exist; integrate them with the automation UI and run history. |
| 20 | Focus session automatically links to a task time log | **Extend.** Persistent focus sessions and Work timers exist; add explicit task linking and one-time completion events. |
| 21 | Habit completion from events, e.g. 25 minutes focus → Study | **Planned.** Configurable event rules with idempotent check-ins and an explanation of the source event. |
| 22 | Archive completed tasks after N days | **Planned.** Add a recoverable archive instead of deleting task history used in reports. |
| 23 | Scheduled encrypted backups to USB/OneDrive/Drive and health warnings | **Extend.** Manual encrypted complete backups and daily local DB snapshots exist. Destination scheduling, key handling and health checks remain. |
| 24 | Email-to-task through filtered IMAP polling | **Planned.** Credentials, polling interval, processed-message identity and filter preview are required. |
| 25 | ICS import/export and Google/Outlook two-way calendar sync | **Extend.** NIBM calendar synchronization exists; general ICS and OAuth provider adapters are separate work. |
| 26 | Local webhook / REST API / CLI for n8n and scripts | **Planned.** Versioned authenticated contract with profile scope, loopback default and explicit permissions. An internal resident tick endpoint is not this public API. |
| 27 | User macros and scheduled scripts | **Planned.** Keep scripts separate from ordinary rule actions, with an explicit trust model and run limits. |
| 28 | Opt-in active-window → project/category time tracking | **Planned.** Clear capture indicator, exclusions, retention and pause/delete controls. |

### Smart / local AI

| # | Feature | Status and scope |
| --- | --- | --- |
| 29 | Local assistant using Ollama/Gemma, e.g. monthly spending questions | **Planned.** Optional local model adapter; numerical answers come from database calculations with the reporting period shown. |
| 30 | Natural-language actions such as moving tasks to tomorrow | **Planned.** Parse to a bounded action preview, identify affected records and support undo. |
| 31 | Spending and habit insights such as weekend overspending | **Extend.** Existing actual-activity summaries and budget alerts provide inputs; trend detection needs adequate data and an explanation. |
| 32 | Burnout/overload warning from tasks, sleep and hours | **Planned.** User-configurable wellbeing prompts, with no diagnosis or inferred sleep when no sleep data exists. |
| 33 | Local receipt OCR → expense using Tesseract | **Planned.** Review merchant, date, currency and amount before posting. |
| 34 | Local Whisper voice note → transcript → tasks | **Planned.** Optional local model installation, microphone consent, transcript correction and task preview. |
| 35 | Summarize notes into action items | **Planned.** Preserve source links and confirm task creation. |
| 36 | Failure Journal root-cause pattern detection | **Extend.** Failure/root-cause records and 90-day repeated-area warnings exist; cross-entry suggestions remain. |

### Employee

| # | Feature | Status and scope |
| --- | --- | --- |
| 37 | Timesheet and overtime calculator | **Extend.** Work manual time logs/timers exist; attendance periods, overtime policies and totals need an employee workflow. |
| 38 | Annual/casual/medical leave balances and planner | **Planned.** Editable employer policies and accrual/carry-over rules. |
| 39 | Payslip gross, EPF/ETF, APIT and net tracker | **Planned.** Rates and effective dates must remain editable; do not ship an unverified current-rate assumption. |
| 40 | Take-home vs commitments forecast | **Extend.** Salary Planner already models monthly income, commitments, saving and investments; add payslip-derived inputs explicitly. |
| 41 | Meeting notes → action items → tasks | **Planned.** Preserve notes and source links; review extraction before task creation. |
| 42 | Automatic yesterday/today/blockers stand-up | **Planned.** Use real task completion dates and explicit blocker records. |
| 43 | Brag document / achievement log for appraisal | **Planned.** Evidence links, dates, outcomes and export. |
| 44 | KPI / OKR tracker | **Extend.** Goals, steps and milestones exist; add measurable key results and review periods. |
| 45 | Travel/meal claims and reimbursements | **Planned.** Claim status and linked expense/reimbursement entries without double-counting. |
| 46 | Work-life alerts, e.g. three days above ten hours | **Extend.** Focus, work logs and personal time exist; configurable threshold rules need complete time inputs. |
| 47 | Job-offer comparison, notice period and probation | **Extend.** Career applications/interviews exist; offer terms and contract timelines remain. |
| 48 | Promotion roadmap, skill gaps and courses | **Extend.** Skills/courses/goals exist; connect criteria, evidence and learning plans. |
| 49 | Side-income tracker and invoice generator | **Extend.** Income tracking exists; invoice numbering, clients, line items, payment status and export remain. |
| 50 | Certification and licence expiry reminders | **Extend.** Generic expiry tracking exists; add career credentials and renewal context. |

### University student

| # | Feature | Status and scope |
| --- | --- | --- |
| 51 | Timetable clashes and module attendance percentage warnings | **Extend.** Weekly timetable and NIBM calendar exist; attendance records and clashes need explicit rules. |
| 52 | Target GPA calculator: marks/grades needed for a goal | **Extend.** Credit-weighted GPA with editable grade points exists; hypothetical target calculations remain. |
| 53 | Assignment backward planning and automatic subtasks | **Extend.** Assignment due dates/status/marks exist; effort estimates and breakdown planning remain. |
| 54 | Exam study planner spreading topics before an exam | **Extend.** Exams exist; topic coverage, availability and revision scheduling remain. |
| 55 | Spaced-repetition flashcards | **Planned.** Decks, review history and local scheduling. |
| 56 | Module-linked Markdown notes with tags and search | **Planned.** A dedicated study-note model and module links, beyond generic documents or Work pages. |
| 57 | APA/Harvard citation manager | **Planned.** Structured reference metadata and explicit style versions. |
| 58 | Group project board and member contribution log | **Extend.** Work boards, people, comments and time logs exist; student group contribution reporting remains. |
| 59 | Past-paper tracker and mistake log | **Planned.** Attempts, scores, topic links and follow-up revision. |
| 60 | Thesis/final-year milestones and supervisor meetings | **Extend.** Goals, milestones and appointments exist; academic project templates and supervision log remain. |
| 61 | Internship/placement tracker and CV/portfolio builder | **Extend.** Career applications/interviews exist; placements, CV and portfolio generation remain. |
| 62 | Fee instalments and scholarships | **Extend.** Bills/financial reminders provide a base; academic fee plans and scholarship status remain. |
| 63 | Library book due dates | **Planned.** Loans, renewals and return state. |
| 64 | Skills/projects/certificates/courses → updated resume | **Extend.** Skills/courses exist; evidence sources, project links and resume generation remain. |

### School student

| # | Feature | Status and scope |
| --- | --- | --- |
| 65 | Homework tracker with subject colours | **Planned.** Age-appropriate task entry and subjects. |
| 66 | School timetable and tomorrow's bag checklist | **Planned.** Link required books/materials to the next school day and holiday calendar. |
| 67 | XP, levels, badges and streaks | **Extend.** Habit streaks exist; configurable school gamification remains. |
| 68 | Parent-approved reward store | **Planned.** Approval and redemption ledger; no purchases or external payments implied. |
| 69 | Pocket money / piggy-bank tracker | **Planned.** Simple child-facing balances with guardian controls. |
| 70 | Tuition schedule and fees | **Planned.** Classes, attendance, fee periods and reminders. |
| 71 | Scholarship / O/L / A/L countdown and syllabus coverage | **Planned.** User-confirmed exam dates and versioned subject/topic lists. |
| 72 | Mistakes notebook and past papers | **Planned.** Reuse the academic attempt/mistake model with the kid-mode interface. |
| 73 | Reading log | **Planned.** Books, sessions, pages and optional reading goals. |
| 74 | Study/screen-time limits and gentle reminders | **Planned.** Distinguish reminders from actual OS enforcement; no implied monitoring without opt-in. |
| 75 | Weekly parent report suitable for WhatsApp/PDF | **Planned.** Guardian-scoped local report/export; sending through WhatsApp is a separate explicit action. |
| 76 | Sports, clubs and extracurricular tracker | **Planned.** Events, participation and goals. |
| 77 | Emoji mood check-in and opt-in parent-visible trend | **Planned.** Sharing is explicit and revocable; avoid exposing private journal details by default. |
| 78 | Chores chart and family shared board | **Planned.** Defined family sharing boundaries across isolated profiles. |

### Sri Lanka specific

| # | Feature | Status and scope |
| --- | --- | --- |
| 79 | Poya/public/bank/mercantile holiday calendar and school terms | **Planned.** Official/imported, versioned data with source, year and type; no generated lunar dates or assumed school terms presented as official. |
| 80 | Sinhala & Tamil New Year spending, gifts and travel planner | **Planned.** Link budgets, checklists, events and trips. |
| 81 | Seettu savings-circle tracker | **Planned.** Contributions, rounds, recipients and arrears; do not treat the ledger as a guaranteed return. |
| 82 | CEB/Water Board/SLT/Dialog utility bills and usage trends | **Extend.** Bill tracking exists; provider fields, readings and usage histories remain. |
| 83 | Vehicle revenue licence, insurance, emissions and service | **Extend.** Vehicle/insurance expiries exist; vehicle entities, mileage and service history remain. |
| 84 | Gold/jewellery holdings and price history | **Extend.** Generic assets/valuations exist; weight/purity, units and sourced dated prices remain. |
| 85 | Fixed-deposit / T-bill rate comparison and maturity reminders | **Extend.** Deposit assets exist; dated comparable terms and maturity events remain. No live rates are bundled by this plan. |
| 86 | Multi-currency and LKR exchange-rate tracker | **Planned.** Store original currency, rate, source/date and LKR equivalent; preserve historical transaction rates. |
| 87 | Gift/event contribution ledger for weddings, funerals and other events | **Planned.** People, event, direction, amount and follow-up context. |
| 88 | Loan/leasing amortization and early settlement simulation | **Extend.** Commitment planning and lend/borrow ledgers exist; interest schedules, fees and settlement assumptions remain. |

### Life, health and money

| # | Feature | Status and scope |
| --- | --- | --- |
| 89 | Water, sleep, exercise, weight and medication reminders | **Planned.** Dedicated records, units and private optional health modules. |
| 90 | Meal planner, grocery list and food budget | **Planned.** Meals/ingredients/shopping with optional expense links. |
| 91 | Subscription/warranty renewal and expiry alerts | **Existing / Extend.** Subscriptions, generic warranties and configurable expiry reminders exist; integrate with the new rule builder. |
| 92 | Snowball/avalanche debt payoff and emergency-fund goal | **Extend.** Emergency-fund and commitment planning exist; debt strategy comparisons remain. |
| 93 | Wishlist with a 30-day cooling-off period | **Planned.** Capture intended cost/date and revisit prompt without automatically buying. |
| 94 | People CRM-lite: birthdays and periodic follow-ups | **Planned.** Contacts, relationship dates and recurring follow-up tasks. |

### Platform

| # | Feature | Status and scope |
| --- | --- | --- |
| 95 | Local-first mobile companion via WiFi PWA or Syncthing | **Planned.** Existing server is loopback-only. A LAN mode requires explicit authenticated pairing and transport design; copying a live SQLite file is not a synchronization protocol. |
| 96 | Optional end-to-end encrypted device sync | **Planned.** Device keys, conflict resolution, attachment sync, revocation and recovery before release. |
| 97 | PIN / Windows Hello app lock and auto-lock | **Extend.** Hashed PIN interface lock exists. Windows Hello, inactivity locking and profile-specific lock behavior remain. A PIN does not encrypt current SQLite data. |
| 98 | Excel/CSV/Notion/Todoist import/export and PDF reports | **Extend.** CSV exports and printable PDF reporting exist; import mappings, previews, duplicate controls and specific format adapters remain. |
| 99 | Community plugin/module system | **Extend.** Folder manifests, module routes/services and checked migrations exist; community installation, compatibility metadata and trust model remain. |
| 100 | Undo history, audit log and privacy dashboard | **Extend.** Some module actions support restore/history. Cross-module undo, consistent actor/action audit and privacy controls remain. |

## Data and runtime architecture

### Profiles and modules

The implemented slice uses the single local profile identity `default` and profile-scoped preferences in the existing `data/lifeos.db`. This does **not** deliver multiple isolated family profiles or profile switching. Personal database paths are not moved during onboarding.

Current schema and configuration:

| Entity | Fields / constraints | Purpose |
| --- | --- | --- |
| `local_profiles` | `id`, `name`, `persona`, `locale`, `template`, `dashboard_layout`, `updated_at` | One current workspace row with ID `default`. Persona/template/locale values are validated by the service. |
| `modules` | `profile_id` references `local_profiles`, `module_id`, `enabled`, `sort_order`; primary key `(profile_id,module_id)` | Persist installed-module preferences. Dashboard, Planner, Settings, Onboarding and Insights remain available. |
| Template catalog | Keys `hnd`, `grade_11`, `software_engineer`, each mapped to a supported persona | Configuration only, using that persona's module/layout defaults; no personal records or populated task templates are inserted. |

Discover and migrate all installed modules before applying profile visibility. Module disablement hides navigation and blocks module requests while retaining tables, service definitions and saved records needed by backups or linked data. Dashboard widgets, quick actions, search results and automatic jobs must also respect enablement. Re-enabling restores access to the existing records.

For #3, introduce an installation-level profile registry and one data root per profile, for example `data/profiles/<opaque-id>/lifeos.db`, with separate uploads, sessions, backups and browser state. Use generated identifiers, not user-entered paths. Authenticate before selecting a profile database and invalidate the previous profile's session/context on switch. Each profile's database may use the same logical local profile identifier because the database boundary isolates its records; the registry remains the authority for installation-wide selection.

Guardian links and permissions require their own registry/policy model. A hidden sidebar, a persona flag or a PIN shared between databases cannot enforce read-only access. Guardian rules must be checked on every relevant server mutation and on report/export access.

### Automation rules and run history

| Entity | Fields / constraints | Purpose |
| --- | --- | --- |
| `automation_rules` | `id`, `profile_id`, name, `enabled`, `trigger`, `conditions` JSON, `actions` JSON, timestamps | User-configured local automation. Validate size, JSON shape and every trigger/condition/action against a versioned allowlist. |
| `automation_runs` | `id`, `rule_id`, `slot`, `status`, `detail`, `created_at`; unique `(rule_id,slot)` | Record one current-day attempt per rule, including complete, skipped or failed outcomes. The rule supplies profile ownership. Failed slots are not automatically retried. |
| `automation_messages` | `id`, `profile_id`, `run_id`, `title`, `body`, `route`, `created_at` | Store local reminders and adapt them to the existing alerts interface. |
| `automation_reports` | `id`, `profile_id`, `run_id`, `kind`, `starts_on`, `ends_before`, `summary` JSON, `created_at` | Retain actual-data summaries and render local printable HTML; no external PDF service. |

Current schedules use `Asia/Colombo`, with local time, selected weekdays and an optional minimum-open-task condition. Each rule gets a unique current-date slot; the claim, local actions and completion commit together. Repeated ticks do not repeat the same rule/date effects. The engine evaluates up to 100 enabled rules per tick and records failures. It does not backfill missed historical days or retry failed slots. Cross-rule deduplication, configurable timezones, event-driven triggers and financial actions are future extensions.

The Windows resident process owns the timer and loopback PHP lifetime. The implemented runner sends sequential background POSTs to `/internal/automation/tick` every minute, including when the main window is hidden. A dedicated random process key is validated independently of the browser session; ordinary rule-builder requests retain authentication/CSRF checks. Requests do not follow redirects or use cookies/proxies, and polling cancels on shutdown. This narrow endpoint is not the planned general REST API. Sleeping or stopped PCs cannot run jobs; resuming evaluates today's eligible slots without replaying missed dates.

Initial actions produce local reminders/reports. Financial posting, task rescheduling and habit completion should call existing domain services so validation, balances, completion history and rollback stay consistent. Module enablement must be checked both when configuring a rule and when it runs. No generic action may execute arbitrary PHP, SQL, shell commands or remote URLs.

### Localization and Sri Lanka data

The current interface shell and onboarding use an English-keyed UTF-8 translation dictionary, English fallback, saved locale selection and the matching HTML language attribute. Continue translating module actions, empty states, validation and generated reports in bounded slices, with Sinhala/Tamil-capable font fallbacks and language review. The current shell language switch does not translate every existing page.

Store holiday date, localized label, applicable holiday types, jurisdiction, source, publication year and dataset revision. Keep school terms separately because they can change by authority or school. Show the covered years and permit corrected official imports. Currency, tax, contribution and interest rates need effective dates, provenance and user overrides; the app must not silently treat a bundled old value as current.

### Privacy and release acceptance

Keep optional AI, voice, OCR, network calendars, email polling, active-window tracking and external backup destinations unconfigured until the user enables and configures them. Explain which local/external process receives data. Encrypted backup scheduling needs a deliberate key-storage/recovery design; do not save a backup passphrase as a plain settings value. Existing complete-backup validation and safety snapshots remain the restore foundation.

Targeted verification is recorded in [foundation verification](../qa/persona-automation-foundation.md): 222 persona checks, 44 HTTP checks, 30 automation checks, a successful C# build and a resident-runner harness. Full release acceptance still needs manual visual/language review and Windows tray/panel/lock/resume/upgrade checks. Keep the remaining entries visible as planned work and record further release evidence before shipping.
