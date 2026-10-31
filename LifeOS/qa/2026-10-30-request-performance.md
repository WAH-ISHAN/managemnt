# Request performance and fresh workspace verification — 2026-10-30

The request profile found that eager module service loading occupied approximately 15 ms of each measured request before route handling. All 27 service files, approximately 242 KB of PHP source, were parsed even for a small calendar configuration response. The calendar frontend also requested that configuration again after every htmx fragment settled.

Changes:

- Module registration builds a service map and autoloads a service when its class is first referenced. Optional `class_exists()` checks continue to load available services, and disabled modules remain unavailable. An explicit manifest alias covers the existing `WebsiteHubTransport` class in `HubTransport.php`. Service maps cannot reference files outside the module's discovered `src/*.php` files.
- Startup only loads encrypted-restore recovery code when a pending recovery journal exists. Recovery still runs before opening the database when needed.
- NIBM configuration refreshes at startup, when the calendar setup page changes, after unlocking, and for explicit sign-in/sync actions. Unrelated navigation and lecture-list refreshes do not generate another configuration request.

## Measurements

`tests/request_performance.py` created a disposable copy of the project and a database containing the existing 4,361-row synthetic load fixture. It instrumented only that disposable bootstrap. The comparative run used 30 samples for each of five routes, with a warmed server and HX fragment responses. Baseline and current variants shared the updated bootstrap; the baseline substituted the original eager `Modules.php`. These numbers therefore isolate module loading rather than claiming a separately measured benefit for the restore-journal guard.

| Route | HTTP median before | HTTP median after | Module registration before | Module registration after |
| --- | ---: | ---: | ---: | ---: |
| Overview | 47.596 ms | 45.427 ms | 15.352 ms | 6.314 ms |
| Planner tasks | 53.297 ms | 48.873 ms | 14.835 ms | 6.167 ms |
| Work | 34.590 ms | 29.209 ms | 15.352 ms | 6.162 ms |
| Calendar configuration | 32.749 ms | 29.938 ms | 15.614 ms | 6.052 ms |
| Notifications | 33.861 ms | 34.043 ms | 15.078 ms | 6.276 ms |

Raw results: `tests/.tmp/request-perf-20261030/comparison.json`. Notifications' HTTP median did not improve in this run even though its module registration time fell. Client transfer, service work, process scheduling and other concurrent machine activity affect total latency. This is a short local HTTP profile; it does not measure browser paint, GPU effects, app memory, native startup or long-term responsiveness.

The calendar UI fixture verifies that 20 unrelated navigations cause **zero additional configuration requests**, while batch changes, pause, unlock and explicit sync still reach the native bridge. No real university network/session is used by that fixture.

## Fresh workspace and regression checks

- **83 fresh workspace checks:** migrate a new disposable database; all personal/history tables start empty; default balances and budgets are zero; no NIBM batch is fabricated; onboarding, the first task and exact-cent income/expense balances persist after reopening.
- **12 module loading checks:** lazy loading, optional availability, PHP case-insensitive class lookup, manifest aliases, unavailable-module removal and invalid/path-escaping map rejection.
- **12 calendar UI request lifecycle checks**, **73 general UI lifecycle checks**, **176 NIBM bridge fixture checks**.
- **228 general HTTP acceptance checks**, **95 Work HTTP checks**, **59 Admin Studio HTTP checks**, **50 NIBM HTTP checks**.
- **45 service/migration checks**, **33 calendar upgrade-preservation checks**, **93 calendar service checks**.
- **24 document/encrypted-backup checks**, **33 cloud Hub authorization/versioning/delivery checks**.
- Changed PHP and JavaScript syntax checks passed.

The new focused suites are included in `scripts/Invoke-QA.ps1`. The complete QA runner and native desktop suite were not run by this audit.

## Installation data boundaries

Source inspection confirmed that `lifeos.iss` excludes `data\*` from its distribution payload and marks the installed data directory `uninsneveruninstall`. `build.ps1` preserves an existing `dist/data` directory and copies only application payload into rebuilt output. Migrations insert editable zero-balance accounts, categories, allocation rules and a grade map; they insert no personal transaction, task, lecture, project or activity records.

This verifies fresh application/database behavior and the packaging source rules. It does not constitute a clean Windows installer execution or an extracted-installer payload audit. The existing `tests/Windows-Smoke.ps1` still expects the older Startup-folder shortcut, whereas the current installer uses the Windows Run registry entry, so that older harness is not fresh evidence for the present installer.
