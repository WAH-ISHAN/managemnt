# LifeOS — Your Life, Your Freedom

සිංහලෙන් සම්පූර්ණ පරිශීලක මාර්ගෝපදේශය: [README-si.md](README-si.md).

Reusable QA workflow: [English playbook](qa/README.md), [Sinhala guide](qa/README-si.md), and [AI agent prompt](qa/AGENT-PROMPT.md). Run the local checks with `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Invoke-QA.ps1`.

**Knowledge Hub:** 20 new local note capabilities, from notebooks, tags and daily notes to links, backlinks, templates and Markdown import/export. See the [feature list and research references](qa/knowledge-hub.md).

LifeOS is a personal life management application for Windows 10/11 x64. It brings study, personal finances, goals, time for yourself and reflection into one workspace. Its purpose is to help you build more choices beyond your working day. PHP 8.3 + SQLite + a C# WebView2 desktop shell keep application data on the laptop, with browser libraries bundled locally. This project is independent of the existing Crab app in the parent directory.

Fresh installations start with onboarding and empty personal records. No demo salary, transactions, investment returns, goals or achievements are included. Default account names, categories and editable allocation rules are configuration; their balances and budgets start at zero. Screens show empty states until you enter your own information.

Version 1.7.0 adds a floating, rounded desktop panel with native Windows 11 acrylic, grouped schedule rows, quick notes and to-dos. Press **Win + \\** to show or hide it. The panel keeps auto-hide and display preferences and suspends its WebView while hidden. Windows 10 and disabled transparency use a readable rounded fallback. Work now includes fixed issue types, project releases/Fix version, and advisory state WIP limits. See the [release verification](qa/2026-10-30-glass-work-1.7.0.md) and [open-source reference report](qa/open-source-jira-references.md).

## What is included

- **Core:** folder modules and manifests, routing, prepared queries, transaction-based migrations with checksums, optional PIN, CSRF protection and local sessions.
- **Your freedom:** financial runway using real linked savings and planned essential costs; optional working-week context; personal time for rest, relationships, health, experiences and learning; weekly totals, editable recent history, goals and upcoming appointments. No synthetic freedom score.
- **Money:** income, automatic percentage allocations, account and asset destinations, expenses, monthly filters, accounts, categories, budgets, allocation history and cashflow charts.
- **Salary planning:** monthly income, bills, loan payments, living costs, savings and investment rates, optional monthly savings increases capped to affordability, emergency funds and a transparent 12-month contribution projection. Explicitly connect a saved plan to real allocation rules for new income.
- **Financial calendar:** bill and loan reminders, due dates, paid expenses, reversible payments, daily/weekly spending and optional recurring income or expenses with duplicate protection.
- **Investments:** businesses, trading accounts, deposits and other assets; deposits, withdrawals, valuations, ROI, value charts, monthly revenue/cost/profit, status and linked financial goals.
- **University:** modules, configurable grade points, credit-weighted GPA, assignments with module/status filters, due dates, weights and marks, exams and a weekly timetable.
- **Daily planner:** tasks, habit check-ins and streaks, weekly routines, appointments and reminders.
- **Goals & career:** personal goals, prerequisite goals and steps, milestones, skills, courses, applications and interviews.
- **Focus & insights:** persistent study/break timers, monthly life reviews, actual activity summaries and dismissible deadline/payment/budget alerts; optional Windows notifications while the app is open.
- **Documents:** validated local uploads, search, categories, downloads, editable details and recoverable Trash; passphrase-encrypted complete database-and-document backups with authenticated restore and safety snapshots.
- **Lessons & growth:** failure journal, root causes, lessons and actions; automatic tasks, task completion and reopening, lesson status, repeated failures within 90 days, weekly review history and score chart.
- **Overview:** active net worth, income/expenses/savings rate, GPA, cashflow, assets, goals, overdue/upcoming assignments, upcoming exams, tasks, review score, accounts and budget warnings.
- **Settings:** profile, PIN, grading scale, allocation rules, accounts, categories, budgets, daily/manual snapshots, database export/restore, CSV exports and a printable report for PDF.
- **Windows app:** one window, automatic loopback port, startup identity check, single instance, PHP cleanup on close/crash, self-contained .NET launcher, bundled PHP, destination-picker installer, shortcuts and uninstaller.

The macOS-inspired interface uses Windows system typography, a grouped sidebar, subtle surfaces, local htmx/Alpine.js/Chart.js, light/dark mode and keyboard-accessible controls. Ctrl K opens page search, Ctrl N creates a task, Ctrl Shift F opens Focus, and Ctrl 1–9 opens sidebar pages. No Node build step or application framework is required.

The task-first interface keeps seven everyday pages visible and groups secondary tools under **More tools**. **+ Add** opens common actions, and **Find** searches actions as well as pages. Add expense opens the expense form and focuses its amount field. Text and controls are larger, date fields offer appropriate Today/Yesterday/Tomorrow shortcuts, and a slide-out menu preserves working space in narrow windows. Buttons and scrollbars follow the selected light/dark theme.

Version 1.3 adds a seven-day planner picker with previous/next week navigation and a return to today. Tasks can be searched by title across all saved records and filtered by All, Open, Overdue or Completed. Overdue means unfinished tasks due before the selected planner date. Results show the matching total and up to 200 entries; narrow the search to find older entries. Editing, completing, reopening or deleting a task preserves the selected date and task filters. Completing a task from the daily view returns to that day.

Version 1.4 introduces a new ribbon-and-leaf LifeOS identity across the sidebar, welcome, unlock, browser favicon, Windows app and installer. Navigation and dialogs use short transitions that respect reduced-motion preferences. Slow page requests display a thin loading bar; in-flight form submissions prevent accidental double saves. Typing no longer scans the whole navigation and chart interface on every keystroke. No new framework or online dependency is added.

Rebuilding preserves any existing `dist/data` directory. Installer payloads explicitly exclude that directory, so local records and WebView profiles are never bundled into a release.

**Your daily progress** reviews actual completed tasks and goal steps, completed study sessions, personal time, income, spending and habit check-ins by date. Review yesterday or the last seven days without an invented productivity score. Task/goal completion dates begin with version 1.2; older completed records without a known completion date are not backfilled. Study sessions are attributed to their completion date. Suggestions use saved task, assignment, exam, appointment and payment dates.

## Manage your businesses and websites

Website Manager is a single-owner workspace for 10 businesses/websites or more in one LifeOS installation. Assign websites to a business, filter across your businesses, and review recent activity from all websites in one overview. A business can have more than one website. Add each website's HTTPS connector endpoint, sign in with your website username/password, and switch between independent admin panels without disconnecting other websites. Each website enforces its own permissions and stores its content on its hosting/VPS. LifeOS does not save website passwords; encrypted local sessions expire within 30 minutes.

The website dashboard includes instant name/domain/hosting search, sign-in and setup filters, recent-connection sorting, session countdowns, a quick-add form, and an in-panel website switcher. Cards use short entrance and hover transitions, with responsive light/dark layouts and reduced-motion support. Status describes the local session; it does not claim that a website is currently reachable.

PHP, WordPress and Next.js/Node.js adapters are included; other backends can implement the same HTTP contract. See the [adapter guide](integrations/website-adapters/README.md).

Deploy or adapt the connector for each real website and create its client accounts before connecting. The bundled reference supports viewer/editor/admin roles for Pages and Products; existing site features require backend-specific integration. See the [deployment and account guide](integrations/website-connector/README.md) and [API contract](integrations/website-connector/PROTOCOL.md).

Progress percentages for personal goals, skills, courses, linked financial goals and emergency savings now use raised doughnut charts with an exact center percentage, completed/remaining labels and a short reveal animation. The graphics use the existing saved progress calculations and respect reduced motion; no overall progress score is fabricated.

The six-month cashflow charts in Money and the dashboard use shaded 3D columns with animated front/top/side faces. The plotted values, axes, hover details and legend filtering still use the recorded income and expenses. Other bar charts retain their existing style.

University opens an overview with separate Module, Assignment, Exam and Timetable-class actions. Each add page has its own form. Exams/classes can be saved before any assignment, and an optional new module can be created in the same transaction. Quick-created modules start ungraded; add credits and grades later in Modules & grades.

Website profiles also track hosting/VPS, domain renewals, maintenance and development costs offline. Record a cost against its site/business, view monthly and all-time totals, and filter its history by month. Choosing a Money account creates one linked expense and account debit atomically; removing that website cost reverses the linked expense. Deleting the expense in Money retains the website cost as tracking-only history. Profiles with cost history cannot be removed until those costs are resolved.

## Run the app

Run `Output/LifeOS-Setup.exe` and choose a writable installation folder on any disk. The desktop shortcut opens LifeOS as a Windows app. The installer includes the offline WebView2 standalone installer for machines without the runtime.

The database is created at `<install>/data/lifeos.db`. Backups, sessions, uploads and the WebView2 profile also live in `data`. The app needs write access to this folder. The installer defaults to a per-user writable location.

To develop without installing, run from this folder with PHP 8.3 and `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, `zip` and `fileinfo` enabled:

```powershell
php -S 127.0.0.1:8787 -t public public/index.php
```

Open `http://127.0.0.1:8787`. Use the router script as shown. Stop with Ctrl+C. For an isolated development database, set `LIFEOS_DATA_DIR` before starting PHP.

## Financial behavior

Start in Salary plan: enter monthly income, bills, loan payments and living costs, then choose savings and investment rates. An optional savings increase is measured in percentage points per month: a 1-point step means 15%, 16%, 17%, and so on. Projections cap that rate at the money available after essentials and investments, using the same cent allocation as real income. Essential costs that already exceed income still show a deficit. No interest, salary growth or market returns are assumed.

Saving a plan creates no transactions. **Use these allocation rules** explicitly replaces the rules for future manual/recurring income with the plan's starting savings and investment rates, selected savings account and investment asset. The remaining share goes to the selected living/bills account. Record actual salary, or configure a recurring salary, to create money entries and linked investment deposits. Review each month's circumstances before applying the projected savings step; projections do not silently change real allocation rules.

Default rules are Needs 50%, Savings 20%, Business 20%, Fun 10%. Active rules cannot exceed 100%. Any remainder goes to Unallocated. Allocation math uses integer cents internally, distributes fractional cents by largest remainder, and stores two-decimal values in SQLite REAL columns. Every allocated cent is accounted for.

Rules apply to new income. Each allocation preserves its rule name, percentage and destination. When an asset override is selected, the share creates a linked asset deposit and does not credit the cash account. Deleting income reverses its account credits and removes its linked asset deposits in one transaction. Auto deposits cannot be deleted independently from the asset screen.

Manual asset deposits and withdrawals can be linked to a cash account to move money between that account and the asset. Leave the account empty for external capital. Deleting such an entry reverses its cash transfer. Expenses reduce the selected account; deleting them restores it. Accounts can become negative if recorded spending exceeds recorded income.

A valuation asserts the asset’s total value on its date. Later deposits increase and withdrawals decrease that value. Same-day entries use insertion order. Future asset entries appear in history but do not affect current value until their date. Profit is current value + withdrawals − deposits; ROI divides profit by total deposits. Business revenue/cost metrics are tracked separately from valuation and cash movements.

Net worth sums active asset values and account balances. Closed assets remain in history and are excluded. Savings rate is `(monthly income − monthly expenses) / monthly income`; the overview separately shows allocations to savings accounts. Asset-linked goals compare current value against the target.

## Learning and university

**NIBM lecture calendar (1.6.1):** open University → Lecture calendar, paste your batch's NIBM dashboard link, then sign in using your email and OTP in the NIBM window. The Windows app syncs new/changed/cancelled lectures to planner appointments every five minutes while open and unlocked. Calendar changes add one update to the notifications panel; repeated syncs do not repeat it. The bell opens a panel with unread, calendar-only and all filters, read/dismiss controls and a Windows popup preference. Your university credentials stay in the university browser session. See [calendar setup, limits and verification](qa/nibm-calendar.md). Windows sign-in startup is available through the installer and [startup helper](scripts/Set-WindowsStartup.ps1).

GPA uses only credits with a recognized grade in the Settings grade map. The initial map is an editable example; set it to your institution’s rules. Assignments remain overdue until marked done, so they do not disappear from the dashboard.

Lesson actions create tasks atomically. Completing the task marks its open lesson applied. Verify a lesson after you confirm the failure has not repeated. An unfinished action must be completed before applied/verified status is allowed. Reopening its task reopens the lesson. Deleting a lesson also removes its linked task.

Reviews use ISO weeks and save one entry per week. The review page shows unfinished tasks and open lessons before the review form. Repeated-failure warnings count entries in the same area over the last 90 days.

## Backups and exports

LifeOS makes one consistent daily SQLite snapshot on use and keeps the latest 14 daily files. Manual, export and before-restore snapshots remain until removed manually. Plain SQLite snapshots contain database records only. Use **Encrypted complete backup** to include uploaded documents. Complete backups use a user-chosen passphrase and authenticated encryption; a wrong passphrase, tampering or an incompatible database is rejected before replacing live records. The live database and uploads remain unencrypted. Copy backups to another disk regularly.

Restore checks database integrity, foreign keys, schema and the module/migration checksums, and saves a safety snapshot before replacing the live database using SQLite’s backup API. A restore requires the matching schema/module set. For older backups, use the matching older release and migrate forward on a copy. PIN sessions are reset after restore.

CSV exports contain headers even for empty tables and are encoded for Excel. Settings/PIN/session tables are excluded. The printable report includes the record tables; use **Print / Save as PDF** and choose a PDF destination in the print dialog. Landscape is recommended for wide tables.

The PIN protects the interface and is hashed with `password_hash`. It does not encrypt the database or backups.

## Build

The build machine needs .NET 8 SDK, Inno Setup 6, PHP 8.3 NTS x64, and Microsoft’s **Evergreen Standalone Installer, x64** from https://developer.microsoft.com/en-us/microsoft-edge/webview2/. Save that runtime installer as `.tools/MicrosoftEdgeWebView2RuntimeInstallerX64.exe`, or provide its path. The standalone installer allows deployment without network access; the small online bootstrapper is not used.

```powershell
./build.ps1
# With explicit tool paths:
./build.ps1 -PhpDirectory C:/php -Dotnet E:/managemnt/LifeOS/.tools/dotnet/dotnet.exe -InnoCompiler E:/managemnt/LifeOS/.tools/Inno/ISCC.exe
```

Output: `Output/LifeOS-Setup.exe`. The build validates prerequisite signatures and includes the PHP extensions and app-local VC runtime. Initial NuGet restore requires internet on the build machine; the installed app operates offline. Development data and test dependencies are not packaged. The application and installer are unsigned until a code-signing certificate is configured.

To upgrade, increase the release version and install over the same folder. Generated data is not an installer payload and remains through upgrade and uninstall. Reinstall to the same location to reconnect that database.

## Tests

```powershell
# PHP 8.3 + Composer (test dependencies only):
composer install
php vendor/phpunit/phpunit/phpunit -c phpunit.xml
# Self-contained service and HTTP acceptance checks:
php tests/services.php
php tests/finance_planning.php
php tests/planner_growth_career.php
php tests/planner_search.php
php tests/documents_encryption.php
php tests/freedom.php
php tests/daily_progress.php
python tests/http_acceptance.py
# Build the installer first; performs an actual disposable Windows installation:
./tests/Windows-Smoke.ps1
# CPU, RAM, system headroom and concurrent reads/writes (disposable data):
python -m pip install psutil
python tests/performance.py --output performance-results.json
```

PHPUnit uses in-memory SQLite databases. The service harness exercises file backups/restore and more integration cases. The HTTP suite copies the app into a temporary project, tests all module flows, and checks the dashboard with University disabled. No tests use personal data.

The Windows test installs to a unique folder under `tests/.tmp`, starts WebView2, checks rendered dashboard/chart initialization, closes normally, checks PHP shutdown, and tests upgrade/uninstall/reinstall preservation. It registers temporary installer shortcuts and removes them during uninstall. Generated test databases remain under `tests/.tmp` for inspection.

Run the installer test before installing your personal copy, or under a disposable Windows account. It refuses to overwrite an already registered personal LifeOS installation. Diagnostic modes require an explicit data directory, and the test always sets a disposable directory instead of using inherited personal data paths.

See `VERIFICATION.md` for the checks completed in this workspace. Using the app daily for one month and validating on a clean Windows 10/11 machine remain release acceptance activities.
See `PERFORMANCE.md` for measured CPU/RAM, response times, concurrent-write integrity and whole-system load on an 8 GB laptop. Raw metrics are in `performance-results.json`.

## Add or disable a module

Create `app/Modules/Example/module.json`, `routes.php`, `src/`, `views/`, and `migrations/001_create.sql`:

```json
{"name":"Example","icon":"diamond","route":"/example","order":60,"version":"1.0.0"}
```

`routes.php` returns `function (Router $r) { ... }`. Migrations run first. Service classes in `src/` load on first reference, including optional `class_exists()` checks; name each service file after its class. For a different filename, add an explicit manifest map such as `"autoload":{"WebsiteHubTransport":"HubTransport.php"}`; values must name files inside that module's `src/` directory. Use a unique module folder name, since migration keys include that name. Keep applied migrations unchanged; add numbered files for updates. Independent modules can be added without changing the core.

Restart to discover modules. To disable one, rename its folder with a `.disabled` suffix, for example `Uni.disabled`. Its data remains in SQLite. The dashboard guards optional service classes. Existing integrations with the disabled module still need its data/schema; do not delete tables to disable a module.

### Provider gateway

Website Manager supports one client sign-in to an agency-owned gateway and all assigned websites. The gateway keeps hosting/origin credentials server-side, supports per-client site/action permissions, and offers direct SQLite-backed public content updates without GitHub Actions. Deploy `integrations/website-gateway` using its README; clients use **Sign in with your website provider**. The gateway service is a separate hosted deployment, not the local LifeOS desktop server.

### Work management (Plane-style workflows)

Open **Work management** in the Every day navigation. This is a native PHP/SQLite module adapted to LifeOS, with workspaces/projects, real issue IDs, configurable workflow states, priorities, multiple assignees, labels, parent/sub-issues and dependency checks. List, Kanban, calendar, spreadsheet and timeline layouts use the same stored work items. Kanban moves and spreadsheet saves enforce optimistic versions.

Cycles include completion snapshots and optional carry-over of unfinished work; modules group work with progress and leads. Saved views retain validated filters/layouts. Project pages support safe Markdown formatting, version history, rollback and conversion into linked issues. Comments, attachments, manual time logs, persistent timers, favourites, My work, intake/triage, archive/trash/restore, activity reports and CSV/JSON exports are included. Work deadlines feed LifeOS reminders, attachments use Documents storage/encrypted backups, and projects may link to website/business profiles.

No example projects, tasks, people or historical metrics are inserted into user data. A workspace uses the existing LifeOS name as its local owner. People/assignees are local contact records; they do not create cloud accounts or send invitations. Cloud collaboration, SSO, AI provider calls and external service automations are separate services and remain unconfigured while cloud setup is held.

See `qa/plane-feature-map.md` for the reference revision, implemented feature map, limits and verification. Plane source is inspected as a reference under `.tools/plane-reference`; it is not bundled as the application's runtime or presented as an installed Plane service.
