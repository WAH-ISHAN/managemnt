# LifeOS 1.3.0 — 28 October 2026

## Changes

- Seven-day planner navigation, previous/next week and return to today.
- Task title search across all records, including those beyond the first 200 displayed.
- All, Open, Overdue and Completed filters with matching totals and clear filters.
- Task edits, completion, reopening and deletion preserve date, search and status filters. Daily task completion returns to the selected day.
- Rebuilds preserve `dist/data`; installers explicitly exclude local records and WebView profiles.

No database migration is needed for this release.

## Verification

- PHPUnit: 10 tests, 33 assertions.
- Services: 45 checks; finance: 55; planner/goals/career: 63; documents/encryption: 24; freedom: 27; daily progress: 21.
- New planner search/navigation regression suite: 20 checks, including older records, literal wildcard search, invalid inputs, year boundaries and return context.
- HTTP acceptance: 175 checks, including redirects preserving task context and rejecting invalid return views before changing records.
- Existing WebView2 UI preflight: 78 checks. Planner screenshot reviewed; the new week picker renders correctly. This preflight does not exercise the new task filters interactively; their behavior is covered by service and HTTP tests.
- Version 1.3 installer compiled successfully. Compression log contains no files from `dist/data`.
- All 173 pre-existing files in `dist/data` retained their SHA-256 hashes after rebuilding and UI testing.

Installer: `Output/LifeOS-Setup.exe`, with checksum alongside it. The installed personal copy has not been upgraded by this work. The new installer has not been run through a fresh installation/uninstallation cycle.
