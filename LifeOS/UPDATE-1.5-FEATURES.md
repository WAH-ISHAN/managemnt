# LifeOS 1.5.1 requested-feature audit

Audited 29 October 2026 against the source in `app/`, `public/`, and `launcher/`. This is a source coverage audit, not a claim that every feature below passed UI testing. Version 1.5.1 adds a glass visual refresh and completes several correction, reminder and export flows for the first 1.5 modules.

**The full requested list has not been implemented.** Version 1.5 added a first version of Personal essentials (expiries, subscriptions, lend/borrow), an optional Small business workspace, and a net-worth explanation page. These modules are available to the same user; there is no separate administrator role. EPF/ETF and local-LLM functionality are excluded by the user's instructions.

Status meanings: **Present** means the described core workflow exists; **Partial** means only part exists; **Missing** means no dedicated workflow was found.

## Trust, planning and life features

| Requested feature | Status | What the code supports / gap |
| --- | --- | --- |
| “Why?” on dashboard numbers | Partial | Net worth links to accounts, active asset values and planned loan records at `/why/net-worth`. Other metrics do not have explanation pages. |
| Expense time cost / freedom days | Missing | Freedom shows runway and entered weekly work hours, but no expense-to-hours or expense-to-runway conversion exists. |
| Sandbox scenarios / forked real data | Missing | Backups exist, but no isolated scenario workspace or scenario calculator exists. |
| Decision journal with later review | Missing | Lessons and monthly/weekly reviews exist, without decision expectations, confidence and scheduled outcome reviews. |
| Promise / commitment tracker | Missing | Tasks and bill commitments exist, without a dedicated person/promise workflow. |
| Unified life timeline | Missing | Each module has its own history; there is no combined life feed. |
| Future-self time capsule | Missing | No dated locked letter workflow. |
| Energy-aware schedule suggestions | Missing | No sleep/mood log or deterministic focus-pattern suggestions. |
| Household LAN sharing and separate profiles | Missing | The app deliberately binds to loopback and has one workspace with optional PIN, not LAN profiles. |

## Sri Lanka and everyday money

| Requested feature | Status | What the code supports / gap |
| --- | --- | --- |
| Seettu tracker | Missing | No seettu group, membership, turn or contribution tables. |
| Lend / borrow ledger | Present | Person, direction, due date, original amount, partial payments, dated repayment history, repayment removal and central due reminders exist. Entries are separate from account balances. |
| Poya / public / mercantile holiday calendar | Missing | No holiday dataset or planning awareness. |
| Utility bills | Partial | Generic bill reminders, recurring expenses and payment history exist. No provider-specific usage readings or usage trend. |
| Festival budget and gift list | Missing | Generic category budgets exist; no festival/gift workflow. |
| LKR amounts | Present | Existing Money and business records use rupee amounts. |

## Relationships and life records

| Requested feature | Status | What the code supports / gap |
| --- | --- | --- |
| People notes, birthdays, last-contact reminders | Missing | No contacts database or follow-up workflow. |
| Gift ideas and gift history | Missing | No gift records. |
| Career networking / referrals | Missing | Applications, interviews and their notes exist, without contacts/referral tracking. |
| Expiry tracker | Present | Title, type, notes, expiry date, reminder window, Done state, manual date updates and central expiry reminders exist. |
| Subscription manager | Present | Amount, monthly/yearly cycle, notes, next renewal, cancellation state, reactivation, monthly equivalent and central renewal reminders exist. Renewal dates are entered manually; no provider is charged or cancelled by the app. |
| Vehicle fuel/service/mileage log | Missing | Vehicle document expiry can be entered, but no running-cost log exists. |
| Encrypted emergency sheet | Missing | Passphrase-encrypted exports exist; no encrypted live emergency-information section. |

## Knowledge, health and learning

| Requested feature | Status | What the code supports / gap |
| --- | --- | --- |
| Markdown notes and backlinks | Missing | Notes fields and lessons exist, without Markdown pages or backlinks. |
| Global full-text data search (FTS5) | Missing | Ctrl K searches page/action names. Planner task-title search and document search are module-specific LIKE queries, not global record search. |
| Idea inbox / Someday triage | Missing | No dedicated capture/triage workflow. |
| Reading / course tracker | Partial | Career courses support progress, status, resources, notes and skill links. No book/reading workflow. |
| Sleep, workouts, water and mood quick log | Missing | Personal-time categories include health; no structured health measurements. |
| Correlation view | Missing | No health/study correlation analysis. |
| Break / eye-rest reminders | Partial | Focus supports study and break sessions and a completion reminder. No scheduled eye-rest nudges within a session. |

## Optional small business

| Requested feature | Status | What the code supports / gap |
| --- | --- | --- |
| Clients and projects | Partial | Projects have a client-name field, hourly rate, notes and active/closed state. No reusable client directory. |
| Project time and income | Present | Manual dated hours, income and cost entries with notes exist. Hours do not automatically create income. |
| Invoice PDF and paid/unpaid status | Missing | No invoice entities, line items, invoice numbering or printable invoice. |
| Project P&L | Present | Recorded income minus recorded cost is shown per project, alongside hours. This is a basic cash-record summary. |
| Personal Business allocation linkage | Partial | Personal income allocation supports business accounts/assets. The optional project ledger is separate and does not change those account balances. |

## Automation, focus and technical features

| Requested feature | Status | What the code supports / gap |
| --- | --- | --- |
| User-defined rules engine | Partial | Income allocation percentages, recurring financial schedules and fixed alerts exist. There is no general trigger/action editor. |
| Local API and webhooks | Missing | Loopback HTML routes and alerts JSON exist; no documented script API, tokens or webhook interface. |
| Plugin / module system | Partial | Modules are discovered from manifests, route files, services and migrations. No user-facing installation or widget management. |
| Data health scan and repair suggestions | Missing | Restore integrity/schema validation exists, but no duplicate/orphan/negative-balance review UI. |
| Time machine / snapshot diff | Partial | Consistent daily/manual backups and validated restore exist. No historical read-only viewer or change comparison. |
| Windows distracting-site/app blocking | Missing | Focus timer exists, without OS blocking. |
| Daily shutdown ritual | Missing | No dedicated end-of-day checklist and tomorrow's top-three flow. |
| Monthly/yearly review PDF | Partial | Monthly summaries/reviews and generic print/PDF table report exist. No purpose-built yearly review or year-in-review export. |
| Portable mode | Partial | Launcher uses an adjacent data folder by default and supports a data-directory environment setting. No explicit USB distribution / portable-mode workflow. |
| Signed automatic updater | Missing | Installer exists; no update feed or updater. |
| Crash-safe migrations / rollback | Partial | Each migration runs in a transaction and existing migration checksums are verified. No automatic whole-upgrade safety backup and recovery/rollback workflow. |
| Accessibility | Partial | Labels, skip link, keyboard command palette, focus states and responsive layouts exist. A complete keyboard/screen-reader/high-contrast audit has not been established. |
| Notion/Todoist/Google Calendar/CSV import | Missing | CSV export exists; no import mapping or connector. |
| Sinhala-friendly UI | Partial | Sinhala README exists and entered Unicode text is supported. The application interface is primarily English. |

## Glass visual refresh in 1.5.1

The shared layout loads a local `glass.css` stylesheet. It adds translucent sidebar, toolbar, cards and dialogs; background colour layers; refined spacing and rounded controls; light/dark colour tokens; and responsive layouts. The stylesheet includes reduced-motion, reduced-transparency, high-contrast, forced-colour, unsupported-blur and print fallbacks. These are implementation provisions, not a certification of accessibility conformance.

## Corrections verified for 1.5.1

- All six `life_*` and `business_*` tables are available through CSV export and the printable report. Full SQLite backups continue to include them.
- Personal essentials expiries, subscriptions and outstanding personal loans participate in central reminders. A fully repaid loan no longer produces a reminder.
- Saved notes and repayment history are visible. Repayments and project entries can be removed from their own parent record to correct mistakes; the displayed balances and project totals are recalculated.
- Expiry and renewal dates can be manually updated. Setting a subscription renewal date reactivates its local tracking state and does not create a financial payment.
- Validation errors from Personal essentials and Small business return to those modules.
- The net-worth explanation receives planned loan source rows as well as the debt total.

Independent isolated SQLite checks verified the three new reminder types, suppression after full repayment, and inclusion of the six export tables. Source inspection confirmed parent-scoped repayment/project-entry removal and manual date updates. The implementation agent also supplied a dedicated automated workflow check; release verification results are reported separately.

## Remaining practical limitations

- The full feature list remains incomplete, as shown above. The visual refresh does not implement the Missing workflows.
- Original loan/person metadata, subscription pricing/name, expiry title/type and project metadata still lack general edit/remove forms. The available correction controls cover repayment/project-entry removal and expiry/renewal dates.
- Project closing has no reopening action. Subscription and expiry date changes update current records rather than retaining a renewal history.
- New Personal essentials and Small business records are intentionally separate ledgers; they do not automatically change personal account balances or recorded net worth.
- “Why?” is a net-worth component explanation, not a general dashboard explainability system.

This report describes release 1.5.1 source coverage. Do not describe it as completion of every feature requested on 29 October.
