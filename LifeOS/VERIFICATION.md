# Verification ? LifeOS 1.2, 25 October 2026

All checks use temporary databases and disposable installations. Personal records were not used or reset.

## Source and service checks

| Check | Result |
| --- | --- |
| PHP source syntax | 78 files passed, including the public entry point |
| JavaScript syntax | app.js and theme.js passed |
| PHPUnit | 10 tests, 33 assertions passed |
| Core service/file integration | 45 checks passed |
| Financial planning, progression and allocation | 55 checks passed |
| Planner, growth and career | 63 checks passed |
| Documents and encrypted backups | 24 checks passed |
| Freedom and personal time | 27 checks passed |
| Daily progress and truthful completion dates | 21 checks passed |
| HTTP acceptance | 171 checks passed |

The 235 service checks include exact-cent allocations, payment and income reversals, monthly recurring transactions and duplicate prevention, savings step-up affordability, income-to-investment deposits, real-savings runway, personal-time validation/history, goals and prerequisites, GPA, lesson/task transitions, file backups, encrypted archive tampering, restore recovery and optional-module behavior.

HTTP acceptance covers onboarding, all module pages, create/edit/status/delete workflows, live salary preview, real salary allocation into an asset and reversal, monthly reviews, reminders, document uploads/downloads/Trash, encrypted export/restore, CSV exports, PIN restart enforcement, foreign/opaque Origin rejection, CSRF, output escaping, private files, ordinary database restore and a disabled University module. It reports no application errors.

## Actual Windows application

The LifeOS 1.2 WebView2 preflight completed successfully in `tests/.tmp/comfort-verified-95e8e9dd256c480ba7f0d0cf5790193e`. Its launcher-smoke.json records 78 successful checks, including 18 light/dark overflow checks at widths 1280, 1024 and 720. Actual quick-add navigation, amount-field focus, date shortcuts, narrow navigation opening/closing, action search and reviewing yesterday/today passed. CapturePreview PNGs and comfort metrics JSON files document readable controls and actual layouts. Test records are not shipped.

Passed flows include fresh onboarding, all visible sidebar pages, real form submission for disposable income, cashflow charts, command search and keyboard shortcut, theme cycling and persistence after reload, salary preview recalculation, focus timer start/tick/pause/resume/finish, and weekly review submission/chart creation. No JavaScript errors were collected.

Native testing exposed a browser-only form rejection: `Referrer-Policy: no-referrer` caused the browser to send `Origin: null` for a normal onboarding POST. The policy now uses `same-origin`, preserving the strict local Origin check. HTTP tests still verify rejection of foreign and opaque origins.

Diagnostic launcher modes require an explicit data directory. The installer smoke test forces a unique disposable directory, removes old success results, restores inherited environment variables and refuses to overwrite a registered personal installation.

`tests/Windows-Smoke.ps1` exercises a real per-user installation on E:, actual WebView2 rendering, PHP cleanup after close, reinstall/upgrade, uninstall and preservation of database records. The full disposable lifecycle baseline passed for version 1.1 before a personal copy was installed. Version 1.2 uses a safe normal upgrade check; the destructive lifecycle suite is not run over the registered personal copy. Generated database files are excluded from the installer payload. `build.ps1` generates a SHA-256 sidecar for the final setup executable.

## Empty production state

The distribution excludes data, databases, uploaded files, test fixtures, test dependencies and build tools. Migrations create only zero-balance accounts, zero-budget categories, editable allocation defaults and grading configuration. Personal income, expenses, assets, goals, tasks, study records and personal time start empty. The app uses No data yet states instead of invented records or returns.

## Environment and remaining acceptance

Tested with PHP 8.3.33 NTS x64, .NET SDK 8.0.426, WebView2 SDK 1.0.2592.51 and Inno Setup 6.7.3 on this Windows x64 machine. The installer and offline runtime prerequisite are bundled; the LifeOS executable and installer remain unsigned.

Clean Windows 10/11 installations without an existing WebView2 runtime, screen-reader review, print-dialog/PDF save interaction and long-duration daily use still need separate manual acceptance. The resource measurements in PERFORMANCE.md were collected on 24 October before the Freedom/progression additions; they are historical measurements, not a benchmark of the new version.
